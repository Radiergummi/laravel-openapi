<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use Override;

use function sprintf;
use function str_replace;

final class HeaderNode implements Node
{
    private ?Node $parent = null;

    public function __construct(
        public readonly string $name,
        public readonly ?string $schema,
        public readonly ?string $description,
        public readonly bool $required,
        public readonly ?OA\Header $raw,
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

    #[Override]
    public function parent(): ?Node
    {
        return $this->parent;
    }

    #[Override]
    public function pointer(string $append = ''): string
    {
        $name = str_replace(['~', '/'], ['~0', '~1'], $this->name);
        $base = ($this->parent?->pointer() ?? '') . '/headers/' . $name;

        return $append !== '' ? $base . '/' . $append : $base;
    }
}
