<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Support\FractalEnvelopeFactory;
use Radiergummi\OpenApi\Plugins\Fractal\Support\SchemaFromTransformer;
use Radiergummi\OpenApi\Plugins\Fractal\Support\Serializer;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
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

    /**
     * @throws ReflectionException
     */
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
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
