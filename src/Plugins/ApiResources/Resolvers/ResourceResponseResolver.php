<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceEnvelopeFactory;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\SchemaFromResource;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Routing\ResourceTarget;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use ReflectionException;

/**
 * Resolves an Eloquent API Resource return type into its `200 OK` response.
 *
 * Defers (returns null) when the action is not a resource endpoint, or when it returns a
 * collection type whose item class is undeclared — the latter is reported by the
 * `resource.response-ambiguous` lint rule.
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
    ) {}

    /**
     * @throws ReflectionException
     */
    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $target = $this->locator->locate($descriptor);

        if ($target === null || $target->isAmbiguous) {
            return null;
        }

        $ref = $this->refFor($target);

        $envelope = match (true) {
            !$target->isCollection => $this->envelopeFactory->single($ref),
            $target->paginated => $this->envelopeFactory->collection($ref),
            default => $this->envelopeFactory->unpaginatedCollection($ref),
        };

        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($envelope)],
        ]);
    }

    /**
     * The qualified `$ref` the envelope wraps: the resource's component, or — for a wrapped-model
     * target (`new JsonResource($model)`) — the model's component directly.
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
