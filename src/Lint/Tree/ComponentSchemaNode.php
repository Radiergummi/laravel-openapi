<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;

use function sprintf;

final class ComponentSchemaNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param list<FieldNode>   $fields
     * @param null|class-string $sourceClass The PHP class that produced this component schema, or
     *                                       null for named-envelope schemas with no source class.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $fields,
        public readonly ?OA\Schema $raw,
        public readonly ?string $sourceClass = null,
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
        $base = "#/components/schemas/{$this->name}";

        return $append !== '' ? "{$base}/{$append}" : $base;
    }
}
