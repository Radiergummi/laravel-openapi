<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Throwable;

use function class_exists;
use function sprintf;

/**
 * Resolves a `#[FractalResponse]`-bound endpoint into its `200 OK` response.
 *
 * Defers (returns null) when the action carries no `#[FractalResponse]`. The
 * transformer's schema is wrapped in the Fractal `data` envelope produced by
 * {@see FractalEnvelopeFactory} — paginated, collection, or single depending on
 * the attribute flags.
 */
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

            $fractalResponse = $attribute->newInstance();

            if (!class_exists($fractalResponse->transformer)) {
                $this->logger->warning(sprintf(
                    '#[FractalResponse] on route %s names unknown transformer %s',
                    $descriptor->route->uri(),
                    $fractalResponse->transformer,
                ));

                return null;
            }

            $ref = $this->schemaFromTransformer->buildRef($fractalResponse->transformer);
            $envelope = $this->envelopeFor($fractalResponse, $ref);

            return new OA\Response([
                'response' => '200',
                'description' => 'OK',
                'content' => [MediaType::Json->schema($envelope)],
            ]);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'FractalResponseResolver failed for route %s: %s',
                $descriptor->route->uri(),
                $e->getMessage(),
            ));

            return null;
        }
    }

    private function envelopeFor(FractalResponse $response, string $ref): OA\Schema
    {
        if ($response->paginated) {
            return $this->envelopeFactory->paginated($ref);
        }

        if ($response->collection) {
            return $this->envelopeFactory->collection($ref);
        }

        return $this->envelopeFactory->single($ref);
    }
}
