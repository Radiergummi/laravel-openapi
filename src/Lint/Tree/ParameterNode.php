<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;

use function sprintf;
use function str_replace;

final class ParameterNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param list<ExampleNode> $examples
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $required,
        public readonly ?string $schema,
        public readonly ?string $description,
        public readonly ?string $pattern,
        public readonly array $examples,
        public readonly ?OA\Parameter $raw,
    ) {}

    /**
     * @throws LogicException if called more than once.
     *
     * @internal Called exactly once by SpecTreeBuilder.
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
