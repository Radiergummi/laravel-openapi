<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use ReflectionException;

use function class_exists;
use function sprintf;

/**
 * Resolves a `#[FractalResponse]`-bound endpoint into its `200 OK` response.
 *
 * Defers (returns null) when the action carries no `#[FractalResponse]`. The
 * transformer's schema is wrapped in the envelope produced by
 * {@see FractalEnvelopeFactory} — its shape determined by the attribute's
 * `paginated` / `collection` flags and `serializer:` (DataArray by default;
 * ArraySerializer and JsonApi modelled too).
 */
#[Scoped]
final readonly class FractalResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private SchemaFromTransformer $schemaFromTransformer,
        private FractalEnvelopeFactory $envelopeFactory,
        private LoggerInterface $logger,
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
            $reflector = $descriptor->actionReflector;

            $attribute = $reflector?->getAttributes(FractalResponse::class)[0] ?? null;

            if ($attribute === null) {
                return null;
            }

            /** @var FractalResponse $fractalResponse */
            $fractalResponse = $attribute->newInstance();

            if (!class_exists($fractalResponse->transformer)) {
                $this->logger->warning(
                    sprintf(
                        '#[FractalResponse] on route %s names unknown transformer %s',
                        $descriptor->route->uri(),
                        $fractalResponse->transformer,
                    ),
                );

                return null;
            }

            $ref = $this->schemaFromTransformer->buildRef($fractalResponse->transformer);
            $envelope = $this->envelopeFor($fractalResponse, $ref);
            $mediaType = $fractalResponse->serializer === Serializer::JsonApi
                ? MediaType::JsonApi
                : MediaType::Json;

            return new OA\Response([
                'response' => '200',
                'description' => 'OK',
                'content' => [$mediaType->schema($envelope)],
            ]);
        } catch (ReflectionException $e) {
            // Tolerate reflection failures only (e.g. a transformer FQCN that class_exists
            // declares present but fails to instantiate via ReflectionClass). Real bugs —
            // attribute constructor TypeErrors, schema-build logic errors — propagate so
            // they surface in dev instead of disappearing into a warning log.
            $this->logger->warning(
                sprintf(
                    'FractalResponseResolver reflection failure for route %s: %s',
                    $descriptor->route->uri(),
                    $e->getMessage(),
                ),
            );

            return null;
        }
    }

    private function envelopeFor(FractalResponse $response, string $ref): OA\Schema
    {
        if ($response->paginated) {
            return $this->envelopeFactory->paginated($ref, $response->serializer);
        }

        if ($response->collection) {
            return $this->envelopeFactory->collection($ref, $response->serializer);
        }

        return $this->envelopeFactory->single($ref, $response->serializer);
    }
}
