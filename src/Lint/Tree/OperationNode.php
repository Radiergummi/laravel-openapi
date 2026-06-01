<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionAttribute;

use function array_any;
use function array_filter;
use function array_values;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;

final class OperationNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param string                                            $pathUri         For API operations: the route URI. For
     *                                                                           webhooks: the webhook name.
     * @param string                                            $method          Uppercase: GET, POST, etc.
     * @param list<ParameterNode>                               $parameters      Path parameters
     * @param list<QueryParameterNode>                          $queryParameters
     * @param list<ResponseNode>                                $responses       All responses
     * @param list<array{scheme: string, scopes: list<string>}> $security
     * @param list<string>                                      $tags
     */
    public function __construct(
        public readonly string $pathUri,
        public readonly string $method,
        public readonly ?string $operationId,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly bool $deprecated,
        public readonly array $parameters,
        public readonly array $queryParameters,
        public readonly ?RequestBodyNode $requestBody,
        public readonly array $responses,
        public readonly array $security,
        public readonly array $tags,
        public readonly ?ActionDescriptor $descriptor,
        public readonly OA\Operation $raw,
        public readonly bool $webhook = false,
    ) {}

    /**
     * @throws LogicException if called more than once.
     *
     * @internal Called exactly once by SpecTreeBuilder after construction.
     */
    public function linkParent(Node $parent): void
    {
        if ($this->parent !== null) {
            throw new LogicException(sprintf('Parent already linked on %s', __CLASS__));
        }

        $this->parent = $parent;
    }

    public function pointer(string $append = ''): string
    {
        if ($this->webhook) {
            $base = $this->parent?->pointer() . '/' . strtolower($this->method);
        } else {
            $escapedPath = str_replace(['~', '/'], ['~0', '~1'], $this->pathUri);
            $base = "#/paths/{$escapedPath}/" . strtolower($this->method);
        }

        return $append !== '' ? $base . '/' . $append : $base;
    }

    public function parent(): ?Node
    {
        return $this->parent;
    }

    /**
     * Success responses (2xx). "default" responses are excluded.
     *
     * @return list<ResponseNode>
     */
    public function successResponses(): array
    {
        return array_values(
            array_filter(
                $this->responses,
                static fn(ResponseNode $r): bool => $r->isSuccess(),
            ),
        );
    }

    /**
     * Error responses (4xx/5xx). "default" responses are excluded.
     *
     * @return list<ResponseNode>
     */
    public function errorResponses(): array
    {
        return array_values(
            array_filter(
                $this->responses,
                static fn(ResponseNode $r): bool => $r->isError(),
            ),
        );
    }

    /**
     * Returns true when the controller method or its declaring class carries
     * `#[PublicEndpoint]`.
     */
    public function hasPublicEndpointAttribute(): bool
    {
        if ($this->descriptor?->method !== null) {
            $attributes = $this->descriptor->method->getAttributes(
                PublicEndpoint::class,
                ReflectionAttribute::IS_INSTANCEOF,
            );

            if ($attributes !== []) {
                return true;
            }
        }

        if ($this->descriptor?->controller !== null) {
            $attributes = $this->descriptor->controller->getAttributes(
                PublicEndpoint::class,
                ReflectionAttribute::IS_INSTANCEOF,
            );

            if ($attributes !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the route carries any `auth:*`, `scope:*`, or
     * `scopes:*` middleware.
     */
    public function hasAuthMiddleware(): bool
    {
        return $this->descriptor !== null && array_any(
            $this->descriptor->route->middleware(),
            static fn(string $mw): bool
                    => str_starts_with($mw, 'auth:')
                    || str_starts_with($mw, 'scope:')
                    || str_starts_with($mw, 'scopes:'),
        );
    }

    /** Convenience: source file path. */
    public function file(): ?string
    {
        return $this->descriptor?->actionReflector?->getFileName() ?: null;
    }

    /** Convenience: source line. */
    public function line(): ?int
    {
        return $this->descriptor?->actionReflector?->getStartLine() ?: null;
    }
}
