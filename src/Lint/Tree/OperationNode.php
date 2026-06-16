<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionAttribute;

use function array_filter;
use function array_values;
use function sprintf;
use function str_replace;

final class OperationNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param string                   $pathUri         Route URI (or webhook name for webhooks).
     * @param list<ParameterNode>      $parameters
     * @param list<QueryParameterNode> $queryParameters
     * @param list<ResponseNode>       $responses
     * @param list<array{
     *     scheme: string,
     *     scopes: list<string>
     * }>                              $security
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $pathUri,
        public readonly HttpMethod $method,
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
     * @internal
     */
    public function linkParent(Node $parent): void
    {
        if ($this->parent !== null) {
            throw new LogicException(
                sprintf('Parent already linked on %s', __CLASS__),
            );
        }

        $this->parent = $parent;
    }

    #[Override]
    public function pointer(string $append = ''): string
    {
        if ($this->webhook) {
            $base = $this->parent?->pointer() . '/' . $this->method->value;
        } else {
            $escapedPath = str_replace(['~', '/'], ['~0', '~1'], $this->pathUri);
            $base = "#/paths/{$escapedPath}/{$this->method->value}";
        }

        return $append !== '' ? $base . '/' . $append : $base;
    }

    #[Override]
    public function parent(): ?Node
    {
        return $this->parent;
    }

    /**
     * @return list<ResponseNode>
     */
    public function successResponses(): array
    {
        return array_values(
            array_filter(
                $this->responses,
                static fn(ResponseNode $response): bool => $response->isSuccess,
            ),
        );
    }

    /**
     * @return list<ResponseNode>
     */
    public function errorResponses(): array
    {
        return array_values(
            array_filter(
                $this->responses,
                static fn(ResponseNode $response): bool => $response->isError,
            ),
        );
    }

    /** True when the method or its declaring class carries `#[PublicEndpoint]`. */
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

    /** Source file path of the action, or null when unavailable. */
    public function file(): ?string
    {
        return $this->descriptor?->actionReflector?->getFileName() ?: null;
    }

    /** Starting line of the action in its source file, or null when unavailable. */
    public function line(): ?int
    {
        return $this->descriptor?->actionReflector?->getStartLine() ?: null;
    }
}
