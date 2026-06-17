<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\SchemaNameCollision;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use ReflectionNamedType;

use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Merges hand-authored swagger-php annotations into the generated document. Per operation it either
 * merges authored responses (authored wins per status code) or, when the return type carries an
 * authored schema and no response body exists yet, attaches that schema as the 200 body. Referenced
 * schemas are registered into {@see ComponentSchemaRegistry} so the post-plugin flush picks them up
 * like any other contributor; responses with unresolvable schema refs are skipped and logged.
 *
 * @internal
 */
#[Scoped]
final readonly class HarvestAuthoredAnnotationsStage implements SpecStage
{
    public function __construct(
        private AuthoredAnnotationScanner $scanner,
        private ComponentSchemaRegistry $schemaRegistry,
        private LoggerInterface $logger,
        private FindingsCollector $findings,
    ) {}

    #[Override]
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        if (is_array($document->paths)) {
            $this->harvestContainers($document->paths, $context);
        }

        if (is_array($document->webhooks)) {
            $this->harvestContainers($document->webhooks, $context);
        }
    }

    /**
     * @param array<OA\PathItem|OA\Webhook> $containers
     */
    private function harvestContainers(array $containers, GenerationContext $context): void
    {
        foreach ($containers as $container) {
            foreach (HttpMethod::cases() as $method) {
                $operation = $container->{$method->value} ?? Generator::UNDEFINED;

                if ($operation instanceof OA\Operation) {
                    $this->harvest($operation, $context);
                }
            }
        }
    }

    private function harvest(OA\Operation $operation, GenerationContext $context): void
    {
        $action = $context->actionFor($operation);

        if ($action === null) {
            return;
        }

        $controller = $action->controller?->getName();
        $method = $action->method?->getName();

        $authoredOperation = $controller !== null && $method !== null
            ? $this->scanner->operationForMethod($controller, $method)
            : null;

        if ($authoredOperation !== null) {
            $this->mergeAuthoredOperation($operation, $authoredOperation);

            return;
        }

        $this->applyReturnTypeSchema($operation, $action);
    }

    /**
     * Merges authored responses onto the generated operation (authored wins per status code).
     */
    private function mergeAuthoredOperation(
        OA\Operation $operation,
        OA\Operation $authored,
    ): void {
        $this->copyAuthoredMetadata($operation, $authored);

        if (!is_array($authored->responses)) {
            return;
        }

        $byStatus = $this->responsesByStatus($operation);

        foreach ($authored->responses as $authoredResponse) {
            if (!is_defined($authoredResponse->response)) {
                continue;
            }

            // An `@OA\Response(ref="#/components/responses/X")` points at a response component this
            // stage does not harvest; merging it verbatim would emit a dangling `$ref`. Skip + log.
            if (is_defined($authoredResponse->ref)) {
                $this->logger->warning(
                    sprintf(
                        'SwaggerPhp harvester: authored response "%s" is a $ref to a response component, '
                        . 'which is not harvested; skipping.',
                        (string) $authoredResponse->response,
                    ),
                );

                continue;
            }

            $schemas = $this->resolveReferencedSchemas($authoredResponse);

            if ($schemas === null) {
                continue;
            }

            foreach ($schemas as $schema) {
                $this->registerSchema($schema);
            }

            $byStatus[(string) $authoredResponse->response] = $authoredResponse;
        }

        $operation->responses = array_values($byStatus);
    }

    /**
     * Copies authored prose/identity onto the generated operation; inferred values are kept for
     * any field the author did not set.
     */
    private function copyAuthoredMetadata(OA\Operation $operation, OA\Operation $authored): void
    {
        if (is_defined($authored->summary)) {
            $operation->summary = $authored->summary;
        }

        if (is_defined($authored->description)) {
            $operation->description = $authored->description;
        }

        if (is_defined($authored->operationId)) {
            $operation->operationId = $authored->operationId;
        }

        if (is_defined($authored->tags)) {
            $operation->tags = $authored->tags;
        }
    }

    /**
     * @return array<string, OA\Response>
     */
    private function responsesByStatus(OA\Operation $operation): array
    {
        $byStatus = [];

        foreach (is_array($operation->responses) ? $operation->responses : [] as $response) {
            $byStatus[(string) $response->response] = $response;
        }

        return $byStatus;
    }

    /**
     * Resolves every schema an authored response references; returns null (and logs) if any is unknown.
     *
     * @return null|list<OA\Schema>
     */
    private function resolveReferencedSchemas(OA\Response $response): ?array
    {
        $schemas = [];

        foreach ($this->collectRefNames($response) as $name) {
            $schema = $this->scanner->schemaForName($name);

            if ($schema === null) {
                $this->logger->warning(
                    sprintf(
                        'SwaggerPhp harvester: authored response references unknown schema "%s"; skipping.',
                        $name,
                    ),
                );

                return null;
            }

            $schemas[] = $schema;
        }

        return $schemas;
    }

    /**
     * Collects `#/components/schemas/*` ref names reachable from an annotation tree.
     *
     * @return list<string>
     */
    private function collectRefNames(object $annotation): array
    {
        $names = [];
        $this->walkRefs($annotation, $names);

        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $names
     */
    private function walkRefs(mixed $node, array &$names): void
    {
        if ($node instanceof OA\Response) {
            foreach (is_array($node->content) ? $node->content : [] as $mediaType) {
                $this->walkRefs($mediaType, $names);
            }

            return;
        }

        if ($node instanceof OA\MediaType) {
            if ($node->schema instanceof OA\Schema) {
                $this->walkRefs($node->schema, $names);
            }

            return;
        }

        if (!$node instanceof OA\Schema) {
            return;
        }

        if (is_string($node->ref)) {
            $name = $this->refName($node->ref);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        if ($node->items instanceof OA\Schema) {
            $this->walkRefs($node->items, $names);
        }

        if ($node->additionalProperties instanceof OA\Schema) {
            $this->walkRefs($node->additionalProperties, $names);
        }

        foreach ([$node->properties, $node->allOf, $node->oneOf, $node->anyOf] as $group) {
            foreach (is_array($group) ? $group : [] as $sub) {
                if ($sub instanceof OA\Schema) {
                    $this->walkRefs($sub, $names);
                }
            }
        }
    }

    private function refName(string $ref): ?string
    {
        return ComponentReference::name($ref);
    }

    /**
     * Registers an authored schema by name and recurses into its referenced schemas. First-wins:
     * a name held by a different schema triggers a collision warning; an identical re-registration
     * is a no-op.
     */
    private function registerSchema(OA\Schema $schema): void
    {
        if (!is_defined($schema->schema)) {
            return;
        }

        $name = $schema->schema;

        $existing = $this->schemaRegistry->schemaForKey($name);

        if ($existing !== null) {
            if ($existing !== $schema) {
                $this->reportSchemaNameCollision($name, $schema);
            }

            return;
        }

        $this->schemaRegistry->registerNamed($name, $schema);

        foreach ($this->collectRefNames($schema) as $referenced) {
            $nested = $this->scanner->schemaForName($referenced);

            if ($nested === null) {
                $this->logger->warning(
                    sprintf(
                        'SwaggerPhp harvester: schema "%s" references unknown schema "%s".',
                        $name,
                        $referenced,
                    ),
                );

                continue;
            }

            $this->registerSchema($nested);
        }
    }

    /**
     * Emits a warning and a `component.schema-name-collision` finding for a colliding schema name.
     */
    private function reportSchemaNameCollision(string $name, OA\Schema $authored): void
    {
        $message = sprintf(
            'SwaggerPhp harvester: authored schema "%s" collides with a component already registered '
            . 'under that name; keeping the existing component and dropping the authored definition.',
            $name,
        );

        $this->logger->warning($message);

        $context = [SchemaNameCollision::CONTEXT_SCHEMA => $name];
        $declaringClass = $this->scanner->declaringClassOf($authored);

        if ($declaringClass !== null) {
            $context[Finding::CONTEXT_SOURCE_CLASS] = $declaringClass;
        }

        $this->findings->emit(
            new Finding(
                ruleId: SchemaNameCollision::ID,
                severity: SchemaNameCollision::SEVERITY,
                message: $message,
                fixHint: SchemaNameCollision::FIX_HINT,
                context: $context,
            ),
        );
    }

    /**
     * Attaches the authored return-type schema as the success body. Fills the existing 2xx response
     * if it has no body; adds a bare 200 when none exists.
     */
    private function applyReturnTypeSchema(
        OA\Operation $operation,
        ActionDescriptor $action,
    ): void {
        $returnClass = $this->singleReturnClass($action);

        if ($returnClass === null) {
            return;
        }

        $schema = $this->scanner->schemaForClass($returnClass);

        if ($schema === null || !is_defined($schema->schema)) {
            return;
        }

        $primary = $this->primarySuccessResponse($operation);

        if ($primary !== null && is_array($primary->content) && $primary->content !== []) {
            return;
        }

        $this->registerSchema($schema);

        $content = [
            MediaType::Json->schema(new OA\Schema(['ref' => ComponentReference::pointer($schema->schema)])),
        ];

        if ($primary !== null) {
            $primary->content = $content;

            return;
        }

        $operation->responses = [
            ...is_array($operation->responses) ? $operation->responses : [],
            new OA\Response(['response' => '200', 'description' => 'OK', 'content' => $content]),
        ];
    }

    private function singleReturnClass(ActionDescriptor $action): ?string
    {
        $returnType = $action->actionReflector?->getReturnType();

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        return $returnType->getName();
    }

    /**
     * Returns the first declared 2xx response, or null if none exists.
     */
    private function primarySuccessResponse(OA\Operation $operation): ?OA\Response
    {
        foreach (is_array($operation->responses) ? $operation->responses : [] as $response) {
            $status = (int) (string) $response->response;

            if ($status >= 200 && $status < 300) {
                return $response;
            }
        }

        return null;
    }
}
