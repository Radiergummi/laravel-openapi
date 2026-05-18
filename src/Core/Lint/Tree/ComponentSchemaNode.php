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

final class ComponentSchemaNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param list<FieldNode> $fields
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $fields,
        public readonly ?OA\Schema $raw,
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
        $base = '#/components/schemas/' . $this->name;

        return $append !== '' ? $base . '/' . $append : $base;
    }
}
