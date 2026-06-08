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
use ReflectionNamedType;

use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;
use function str_starts_with;
use function strrpos;
use function substr;

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
 * Every referenced authored schema is registered into the document's components, transitively and
 * under its exact authored name. A response referencing a schema that cannot be resolved is skipped
 * and logged rather than emitted as a dangling `$ref`.
 *
 * @internal
 */
#[Scoped]
final readonly class HarvestAuthoredAnnotationsStage implements SpecStage
{
    public function __construct(
        private AuthoredAnnotationScanner $scanner,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        if (!is_array($document->paths)) {
            return;
        }

        foreach ($document->paths as $container) {
            foreach (HttpMethod::cases() as $method) {
                $operation = $container->{$method->value} ?? Generator::UNDEFINED;

                if ($operation instanceof OA\Operation) {
                    $this->harvest($operation, $document, $context);
                }
            }
        }
    }

    private function harvest(OA\Operation $operation, OA\OpenApi $document, GenerationContext $context): void
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
            $this->mergeAuthoredOperation($operation, $authoredOperation, $document);

            return;
        }

        $this->applyReturnTypeSchema($operation, $action, $document);
    }

    /**
     * Copies the authored operation's prose/metadata and responses onto the generated operation.
     * Responses merge per status, authored winning; a response whose referenced schemas cannot all
     * be resolved is skipped and logged.
     */
    private function mergeAuthoredOperation(
        OA\Operation $operation,
        OA\Operation $authored,
        OA\OpenApi $document,
    ): void {
        $this->copyAuthoredMetadata($operation, $authored);

        if (!is_array($authored->responses)) {
            return;
        }

        $byStatus = $this->responsesByStatus($operation);

        foreach ($authored->responses as $authoredResponse) {
            $schemas = $this->resolveReferencedSchemas($authoredResponse);

            if ($schemas === null) {
                continue;
            }

            foreach ($schemas as $schema) {
                $this->registerSchema($document, $schema);
            }

            $byStatus[(string) $authoredResponse->response] = $authoredResponse;
        }

        $operation->responses = array_values($byStatus);
    }

    /**
     * Adopts the authored operation's prose and identity. The authored annotation is the source of
     * truth for the operation it describes, so its summary/description replace the library's
     * docblock-derived values outright (an `@OA` docblock would otherwise leak the raw annotation
     * text into them); operationId and tags are taken only when the author set them.
     */
    private function copyAuthoredMetadata(OA\Operation $operation, OA\Operation $authored): void
    {
        $operation->summary = $authored->summary;
        $operation->description = $authored->description;

        if (is_defined($authored->operationId)) {
            $operation->operationId = $authored->operationId;
        }

        if (is_defined($authored->tags)) {
            $operation->tags = $authored->tags;
        }
    }

    /**
     * Attaches an authored schema as the 200 body when the typed return resolves to one and the
     * operation does not already carry a response body for 200.
     */
    private function applyReturnTypeSchema(
        OA\Operation $operation,
        ActionDescriptor $action,
        OA\OpenApi $document,
    ): void {
        $returnClass = $this->singleReturnClass($action);

        if ($returnClass === null) {
            return;
        }

        $schema = $this->scanner->schemaForClass($returnClass);

        if ($schema === null || !is_string($schema->schema) || !$this->primaryIsEmpty($operation)) {
            return;
        }

        $this->registerSchema($document, $schema);

        $byStatus = $this->responsesByStatus($operation);
        $byStatus['200'] = new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [
                MediaType::Json->schema(new OA\Schema(['ref' => '#/components/schemas/' . $schema->schema])),
            ],
        ]);

        $operation->responses = array_values($byStatus);
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
     * Registers an authored schema into the document components under its authored name, then
     * recurses into the schemas it references. Deduplication by name doubles as the cycle guard.
     */
    private function registerSchema(OA\OpenApi $document, OA\Schema $schema): void
    {
        if (!is_string($schema->schema)) {
            return;
        }

        $components = $this->components($document);

        foreach ($components->schemas as $existing) {
            if ($existing->schema === $schema->schema) {
                return;
            }
        }

        $components->schemas[] = $schema;

        foreach ($this->collectRefNames($schema) as $name) {
            $nested = $this->scanner->schemaForName($name);

            if ($nested === null) {
                $this->logger->warning(sprintf(
                    'SwaggerPhp harvester: schema "%s" references unknown schema "%s".',
                    $schema->schema,
                    $name,
                ));

                continue;
            }

            $this->registerSchema($document, $nested);
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

    private function primaryIsEmpty(OA\Operation $operation): bool
    {
        foreach (is_array($operation->responses) ? $operation->responses : [] as $response) {
            if ((string) $response->response === '200' && is_array($response->content) && $response->content !== []) {
                return false;
            }
        }

        return true;
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
        if (!str_starts_with($ref, '#/components/schemas/')) {
            return null;
        }

        $position = strrpos($ref, '/');

        return $position === false ? null : substr($ref, $position + 1);
    }

    private function components(OA\OpenApi $document): OA\Components
    {
        if (!$document->components instanceof OA\Components) {
            $document->components = new OA\Components([]);
        }

        if (!is_array($document->components->schemas)) {
            $document->components->schemas = [];
        }

        return $document->components;
    }
}
