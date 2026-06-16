<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Override;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class FindingLocation implements Arrayable, JsonSerializable
{
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?string $routeName = null,
        public ?HttpMethod $routeMethod = null,
        public ?string $routeUri = null,
        public ?string $jsonPointer = null,
    ) {}

    public static function fromDescriptor(ActionDescriptor $descriptor): self
    {
        return new self(
            file: $descriptor->actionReflector?->getFileName() ?: null,
            line: $descriptor->actionReflector?->getStartLine() ?: null,
            routeName: $descriptor->route->getName(),
            routeMethod: $descriptor->httpMethod,
            routeUri: $descriptor->route->uri(),
        );
    }

    /**
     * Builds a location from an OperationNode.
     */
    public static function fromOperation(OperationNode $operation): self
    {
        $descriptor = $operation->descriptor;

        // Webhook operations are not routes; omit route fields to avoid bogus output.
        if ($operation->webhook) {
            return new self(
                file: $descriptor?->actionReflector?->getFileName() ?: null,
                line: $descriptor?->actionReflector?->getStartLine() ?: null,
                routeMethod: $operation->method,
            );
        }

        return new self(
            file: $descriptor?->actionReflector?->getFileName() ?: null,
            line: $descriptor?->actionReflector?->getStartLine() ?: null,
            routeName: $descriptor?->route->getName(),
            routeMethod: $operation->method,
            routeUri: $operation->pathUri,
        );
    }

    /**
     * Returns a new instance with null fields filled from `$defaults`.
     */
    public function withDefaults(self $defaults): self
    {
        return new self(
            file: $this->file ?? $defaults->file,
            line: $this->line ?? $defaults->line,
            routeName: $this->routeName ?? $defaults->routeName,
            routeMethod: $this->routeMethod ?? $defaults->routeMethod,
            routeUri: $this->routeUri ?? $defaults->routeUri,
            jsonPointer: $this->jsonPointer ?? $defaults->jsonPointer,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    #[Override]
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'routeName' => $this->routeName,
            'routeMethod' => $this->routeMethod,
            'routeUri' => $this->routeUri,
            'jsonPointer' => $this->jsonPointer,
        ];
    }
}
