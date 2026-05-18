<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Core\Registry\ResolvedSchema;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use OpenApi\Annotations as OA;

use function in_array;
use function sprintf;
use function strtoupper;

/**
 * Iterates registered {@see RequestSchemaResolver}s (first non-null wins) and assembles an
 * {@see OA\RequestBody}. Emits a `request.empty` finding when no resolver matches a
 * write-method action.
 */
final readonly class RequestBodyExtractor
{
    public function __construct(
        /** @var list<RequestSchemaResolver> */
        private array $resolvers,
        private FindingsCollector $findings,
    ) {}

    public function extractFromMethod(ActionDescriptor $descriptor): ?OA\RequestBody
    {
        foreach ($this->resolvers as $resolver) {
            $resolved = $resolver->resolveRequestSchema($descriptor);

            if ($resolved !== null) {
                return $this->buildRequestBody($resolved);
            }
        }

        $this->emitEmptyFindingIfWriteMethod($descriptor);

        return null;
    }

    private function buildRequestBody(ResolvedSchema $resolved): OA\RequestBody
    {
        return new OA\RequestBody([
            'required' => true,
            'content' => [
                $resolved->mediaType->schema(new OA\Schema([
                    'ref' => "#/components/schemas/{$resolved->componentKey}",
                ])),
            ],
        ]);
    }

    private function emitEmptyFindingIfWriteMethod(ActionDescriptor $descriptor): void
    {
        $httpMethod = strtoupper($descriptor->route->methods()[0] ?? 'GET');

        if (in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true)) {
            $this->findings->emit(
                new Finding(
                    ruleId: 'request.empty',
                    level: 2,
                    message: sprintf(
                        'No request body schema for %s %s',
                        $httpMethod,
                        $descriptor->route->uri(),
                    ),
                    location: new FindingLocation(
                        file: $descriptor->method?->getFileName() ?: null,
                        line: $descriptor->method?->getStartLine() ?: null,
                        routeName: $descriptor->route->getName(),
                        routeMethod: $httpMethod,
                        routeUri: $descriptor->route->uri(),
                    ),
                    fixHint: 'Type-hint a Data class or FormRequest on the controller or injected Action.',
                ),
            );
        }
    }
}
