<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;

use function sprintf;
use function str_replace;

final class LinkNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param array<string, string> $parameters Link parameter mappings
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $operationId,
        public readonly ?string $operationRef,
        public readonly array $parameters,
        public readonly ?string $description,
        public readonly ?OA\Link $raw,
    ) {}

    /**
     * @internal Called exactly once by SpecTreeBuilder.
     *
     * @throws LogicException if called more than once.
     */
    public function linkParent(Node $parent): void
    {
        if ($this->parent !== null) {
            throw new LogicException(sprintf('Parent already linked on %s', static::class));
        }

        $this->parent = $parent;
    }

    public function parent(): ?Node
    {
        return $this->parent;
    }

    public function pointer(string $append = ''): string
    {
        $name = str_replace(['~', '/'], ['~0', '~1'], $this->name);
        $base = ($this->parent?->pointer() ?? '') . '/links/' . $name;

        return $append !== '' ? $base . '/' . $append : $base;
    }

    /** The enclosing response. */
    public function response(): ?ResponseNode
    {
        return $this->parent instanceof ResponseNode ? $this->parent : null;
    }
}
