<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceEnvelopeFactory;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\SchemaFromResource;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
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
    ) {}

    /**
     * @throws ReflectionException
     */
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $target = $this->locator->locate($descriptor);

        if ($target === null || $target->isAmbiguous()) {
            return null;
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $target->resourceClass;
        $ref = $this->schemaFromResource->buildRef($resourceClass);

        $envelope = $target->isCollection
            ? $this->envelopeFactory->collection($ref)
            : $this->envelopeFactory->single($ref);

        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($envelope)],
        ]);
    }
}
