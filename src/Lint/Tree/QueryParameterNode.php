<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;

use function sprintf;
use function str_replace;

final class QueryParameterNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param list<ExampleNode> $examples
     * @param null|list<mixed>  $enum
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $required,
        public readonly ?string $type,
        public readonly bool $hasSchema,
        public readonly ?string $style,
        public readonly ?bool $explode,
        public readonly ?string $description,
        public readonly ?array $enum,
        public readonly array $examples,
        public readonly ?OA\Parameter $raw,
    ) {}

    /**
     * @internal Called exactly once by SpecTreeBuilder.
     *
     * @throws LogicException if called more than once.
     */
    public function linkParent(Node $parent): void
    {
        if ($this->parent !== null) {
            throw new LogicException(sprintf('Parent already linked on %s', __CLASS__));
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
        $base = ($this->parent?->pointer() ?? '') . '/parameters/' . $name;

        return $append !== '' ? $base . '/' . $append : $base;
    }
}
