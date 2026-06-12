<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use Override;

use function array_filter;
use function implode;
use function sprintf;
use function str_replace;

final class FieldNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param list<FieldNode>   $children Nested object properties
     * @param list<ExampleNode> $examples
     * @param null|list<mixed>  $enum     Enum values if present
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $type,
        public readonly bool $required,
        public readonly bool $nullable,
        public readonly ?string $description,
        public readonly ?string $format,
        public readonly mixed $example,
        public readonly ?array $enum,
        public readonly array $children,
        public readonly array $examples,
        public readonly ?string $ref,
        public readonly ?OA\Property $raw,
    ) {}

    /**
     * @throws LogicException if called more than once.
     *
     * @internal Called exactly once by SpecTreeBuilder.
     */
    public function linkParent(Node $parent): void
    {
        if ($this->parent !== null) {
            throw new LogicException(
                sprintf('Parent already linked on %s', self::class),
            );
        }

        $this->parent = $parent;
    }

    #[Override]
    public function pointer(string $append = ''): string
    {
        return implode('/', array_filter([
            $this->parent?->pointer(),
            'properties',
            str_replace(['~', '/'], ['~0', '~1'], $this->name),
            $append,
        ]));
    }

    /**
     * Walk up the tree to find the enclosing OperationNode (if any).
     */
    public function operation(): ?OperationNode
    {
        $node = $this->parent;

        while ($node !== null) {
            if ($node instanceof OperationNode) {
                return $node;
            }

            $node = $node->parent();
        }

        return null;
    }

    #[Override]
    public function parent(): ?Node
    {
        return $this->parent;
    }

}
