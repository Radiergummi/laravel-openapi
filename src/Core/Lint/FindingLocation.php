<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Override;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

use function strtoupper;

final readonly class FindingLocation implements Arrayable, JsonSerializable
{
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?string $routeName = null,
        public ?string $routeMethod = null,
        public ?string $routeUri = null,
        public ?string $jsonPointer = null,
    ) {}

    public static function fromDescriptor(ActionDescriptor $descriptor): self
    {
        return new self(
            file: $descriptor->actionReflector?->getFileName() ?: null,
            line: $descriptor->actionReflector?->getStartLine() ?: null,
            routeName: $descriptor->route->getName(),
            routeMethod: strtoupper($descriptor->route->methods()[0] ?? 'GET'),
            routeUri: $descriptor->route->uri(),
        );
    }

    /**
     * Build a location from an OperationNode, pulling file/line from the descriptor (if available)
     * and route info from the node itself.
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
     * Return a new instance where any null fields are filled from `$defaults`.
     *
     * Explicit values on `$this` always win; only missing (null) fields are populated from
     * the defaults.
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

    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
