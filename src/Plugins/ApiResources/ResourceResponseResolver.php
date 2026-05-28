<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionException;

use function sprintf;

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
        private ResourceClassLocator $locator,
        private SchemaFromResource $schemaFromResource,
        private ResourceEnvelopeFactory $envelopeFactory,
        private LoggerInterface $logger,
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
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
        } catch (ReflectionException $exception) {
            // Tolerate reflection failures only (e.g. a resource class that disappears between
            // locator resolution and schema build). Real bugs — attribute construction errors,
            // schema-build logic errors — propagate so they surface in dev rather than
            // disappearing into a warning log.
            $this->logger->warning(
                sprintf(
                    'ResourceResponseResolver reflection failure for route %s: %s',
                    $descriptor->route->uri(),
                    $exception->getMessage(),
                ),
            );

            return null;
        }
    }
}
