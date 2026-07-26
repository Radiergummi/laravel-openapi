<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceEnvelopeFactory;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceToArrayReader;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\SchemaFromResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\WrappedModelLocator;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Routing\ResourceTarget;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

use function sprintf;

/**
 * Resolves an Eloquent API Resource return type into its success response: the status the action
 * authored on a `response()->json(<resource>, <status>)` wrapper, or `200 OK` otherwise.
 *
 * Returns null when the action is not a resource endpoint, or when it returns a collection
 * type whose item class is undeclared (the latter is reported by the
 * `resource.response-ambiguous` lint rule).
 */
#[Scoped]
final readonly class ResourceResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private ResourceTargetLocator $locator,
        private SchemaFromResource $schemaFromResource,
        private ResourceEnvelopeFactory $envelopeFactory,
        private EloquentModelToSchema $modelToSchema,
        private ComponentSchemaRegistry $componentRegistry,
        private FindingsCollector $findings,
        private ResourceToArrayReader $toArrayReader,
        private WrappedModelLocator $wrappedModelLocator,
    ) {}

    /**
     * @throws ReflectionException
     */
    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?PrimaryResponse
    {
        $target = $this->locator->locate($descriptor);

        if ($target === null || $target->isAmbiguous) {
            return null;
        }

        $this->emitEmptyResourceFinding($descriptor, $target);

        $ref = $this->refFor($target);

        $envelope = match (true) {
            !$target->isCollection => $this->envelopeFactory->single($ref),
            $target->paginated => $this->envelopeFactory->collection($ref),
            default => $this->envelopeFactory->unpaginatedCollection($ref),
        };

        $status = $target->successStatus ?? 200;

        return PrimaryResponse::of(new OA\Response([
            'response' => (string) $status,
            'description' => HttpFoundationResponse::$statusTexts[$status] ?? sprintf('HTTP %d', $status),
            'content' => [MediaType::Json->schema($envelope)],
        ]), statusIsExplicit: $target->successStatus !== null);
    }

    /**
     * Reports a resource response the generator cannot give a real schema, at generation time
     * rather than by re-reading the resource in a lint rule. Two disjoint cases, preserving which
     * rule ID fires: the base/abstract `JsonResource` with no inferable shape ships an empty
     * `{data: {}}` envelope (`resource.response-empty`); a concrete resource with no
     * `#[ResourceField]` and no inferable shape has an empty schema (`resource.fields-undeclared`).
     *
     * @throws ReflectionException
     */
    private function emitEmptyResourceFinding(ActionDescriptor $descriptor, ResourceTarget $target): void
    {
        $resourceClass = $target->resourceClass;

        // Wrapped-model targets document the model's schema, not an empty envelope.
        if ($resourceClass === null) {
            return;
        }

        $isBaseOrAbstract = $resourceClass === JsonResource::class
            || new ReflectionClass($resourceClass)->isAbstract();

        // A concrete resource that already declares its output keys is fully specified.
        if (
            !$isBaseOrAbstract
            && new ReflectionClass($resourceClass)->getAttributes(ResourceField::class) !== []
        ) {
            return;
        }

        // Shared emptiness gate: skip when the shape is inferable from toArray() or a wrapped model.
        /** @var class-string<JsonResource> $resourceClass */
        $inferred = $this->toArrayReader->read($resourceClass);

        if ($inferred !== null && $inferred->fields !== []) {
            return;
        }

        if ($inferred === null && $this->wrappedModelLocator->locate($resourceClass) !== null) {
            return;
        }

        $controllerName = $descriptor->controller?->getName();
        $context = $controllerName !== null ? [Finding::CONTEXT_SOURCE_CLASS => $controllerName] : [];
        $location = FindingLocation::fromDescriptor($descriptor);
        $method = $descriptor->httpMethod?->forDisplay() ?? '?';
        $uri = $descriptor->route->uri();

        $this->findings->emit($isBaseOrAbstract
            ? new Finding(
                ruleId: 'resource.response-empty',
                severity: Severity::Degraded,
                message: sprintf(
                    '%s %s resolves to %s — the response schema is an empty {data: {}} envelope',
                    $method,
                    $uri,
                    $resourceClass,
                ),
                location: $location,
                fixHint: 'Type the return to a concrete JsonResource (with #[ResourceField]s or an @mixin model), '
                . 'or add #[ResponseResource(SomeResource::class)] to the action.',
                context: $context,
            )
            : new Finding(
                ruleId: 'resource.fields-undeclared',
                severity: Severity::Degraded,
                message: sprintf(
                    '%s %s returns %s but it declares no #[ResourceField] — the response schema is empty',
                    $method,
                    $uri,
                    $resourceClass,
                ),
                location: $location,
                fixHint: 'Declare each output key with a class-level #[ResourceField] on the resource, '
                . 'or add an @mixin model annotation the generator can resolve fields against.',
                context: $context,
            ));
    }

    /**
     * The qualified `$ref` the envelope wraps: the resource's component, or, for a wrapped-model
     * target (`new JsonResource($model)`), the model's component directly.
     *
     * @throws ReflectionException
     */
    private function refFor(ResourceTarget $target): string
    {
        if ($target->modelClass !== null) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $target->modelClass;

            return $this->componentRegistry->qualifyKey($this->modelToSchema->build($modelClass));
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $target->resourceClass;

        return $this->schemaFromResource->buildRef($resourceClass);
    }
}
