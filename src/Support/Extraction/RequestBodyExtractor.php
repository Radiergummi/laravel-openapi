<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;
use Radiergummi\OpenApi\Support\Registry\ResolverFaultBoundary;

use function in_array;
use function sprintf;

/**
 * Iterates registered {@see RequestSchemaResolver}s (first non-null wins) and assembles an
 * {@see OA\RequestBody}. Emits a `request.empty` finding when no resolver matches a
 * write-method action.
 *
 * @internal
 */
final readonly class RequestBodyExtractor
{
    public function __construct(
        /** @var list<RequestSchemaResolver> */
        private array $resolvers,
        private FindingsCollector $findings,
        private ResolverFaultBoundary $faultBoundary,
    ) {}

    public function extractFromMethod(ActionDescriptor $descriptor): ?OA\RequestBody
    {
        foreach ($this->resolvers as $resolver) {
            $resolved = $this->faultBoundary->isolate(
                $resolver::class,
                $descriptor,
                fn(): ?ResolvedSchema => $resolver->resolveRequestSchema($descriptor),
            );

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
                    'ref' => ComponentReference::pointer($resolved->componentKey),
                ])),
            ],
        ]);
    }

    private function emitEmptyFindingIfWriteMethod(ActionDescriptor $descriptor): void
    {
        if (in_array($descriptor->httpMethod, [HttpMethod::Post, HttpMethod::Put, HttpMethod::Patch], true)) {
            $this->findings->emit(
                new Finding(
                    ruleId: 'request.empty',
                    level: 2,
                    message: sprintf(
                        'No request body schema for %s %s',
                        $descriptor->httpMethod->forDisplay(),
                        $descriptor->route->uri(),
                    ),
                    location: new FindingLocation(
                        file: $descriptor->method?->getFileName() ?: null,
                        line: $descriptor->method?->getStartLine() ?: null,
                        routeName: $descriptor->route->getName(),
                        routeMethod: $descriptor->httpMethod,
                        routeUri: $descriptor->route->uri(),
                    ),
                    fixHint: 'Type-hint a Data class or FormRequest on the controller or injected Action.',
                ),
            );
        }
    }
}
