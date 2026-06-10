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
 * Merges the hand-authored swagger-php annotations a host app already wrote into the generated
 * document, without taking over the operation skeleton the library inferred from routes.
 *
 * For each generated operation it either:
 *
 *  - copies the matching authored operation's `@OA\Response`s onto the operation (authored wins
 *    per status code; inferred responses for other statuses are kept), or
 *  - when the return type resolves to a class carrying an authored `#[OA\Schema]` / `@OA\Schema`
 *    and the operation has no response body yet, attaches that schema as the `200` body.
 *
 * Every referenced authored schema is registered into the shared {@see ComponentSchemaRegistry}
 * (transitively, under its exact authored name via {@see ComponentSchemaRegistry::registerNamed()}),
 * so the post-plugin `ComponentsStage` flush picks them up like any other contributor's schemas — no
 * direct document writes, O(1) dedup, and schema-transformer dispatch for free. A response
 * referencing a schema that cannot be resolved is skipped and logged rather than emitted as a
 * dangling `$ref`.
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
     * Copies the authored operation's prose/metadata and responses onto the generated operation.
     * Responses merge per status, authored winning; a response whose referenced schemas cannot all
     * be resolved is skipped and logged.
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
                $this->logger->warning(sprintf(
                    'SwaggerPhp harvester: authored response "%s" is a $ref to a response component, '
                    . 'which is not harvested; skipping.',
                    (string) $authoredResponse->response,
                ));

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
     * Adopts the authored operation's prose and identity. The authored annotation is the source of
     * truth for the operation it describes, so each field the author set replaces the library's
     * inferred value; fields the author left unset keep whatever the library inferred (an authored
     * `@OA` operation that documents only responses must not erase the route's docblock summary).
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
     * Attaches an authored schema as the operation's success body when the typed return resolves to
     * one. The schema fills the existing primary 2xx response (e.g. a convention `201 Created`) when
     * that response has no body yet; only when there is no success response at all is a `200` added,
     * so the operation never ends up with two success codes.
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

    /**
     * Resolves every schema an authored response references, or null when any of them is unknown
     * (the caller then skips the response). Logs each unresolvable name.
     *
     * @return null|list<OA\Schema>
     */
    private function resolveReferencedSchemas(OA\Response $response): ?array
    {
        $schemas = [];

        foreach ($this->collectRefNames($response) as $name) {
            $schema = $this->scanner->schemaForName($name);

            if ($schema === null) {
                $this->logger->warning(sprintf(
                    'SwaggerPhp harvester: authored response references unknown schema "%s"; skipping.',
                    $name,
                ));

                return null;
            }

            $schemas[] = $schema;
        }

        return $schemas;
    }

    /**
     * Registers an authored schema into the shared {@see ComponentSchemaRegistry} under its authored
     * name, then recurses into the schemas it references. The registry's name-keyed idempotency
     * provides O(1) dedup and doubles as the cycle guard; the post-plugin `ComponentsStage` flush
     * writes the accumulated schemas into the document.
     */
    private function registerSchema(OA\Schema $schema): void
    {
        if (!is_defined($schema->schema)) {
            return;
        }

        $name = $schema->schema;

        if ($this->schemaRegistry->hasKey($name)) {
            return;
        }

        $this->schemaRegistry->registerNamed($name, $schema);

        foreach ($this->collectRefNames($schema) as $referenced) {
            $nested = $this->scanner->schemaForName($referenced);

            if ($nested === null) {
                $this->logger->warning(sprintf(
                    'SwaggerPhp harvester: schema "%s" references unknown schema "%s".',
                    $name,
                    $referenced,
                ));

                continue;
            }

            $this->registerSchema($nested);
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
     * The operation's primary success response — the first declared `2xx` — or null when it
     * declares none yet.
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

    private function singleReturnClass(ActionDescriptor $action): ?string
    {
        $returnType = $action->actionReflector?->getReturnType();

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        return $returnType->getName();
    }

    /**
     * Collects the names of `#/components/schemas/*` refs reachable from an annotation's in-memory
     * object tree (does not follow the refs themselves).
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
}
