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

use function sprintf;

final class WebhookNode implements Node
{
    private ?Node $parent = null;

    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly OperationNode $operation,
        public readonly mixed $raw,
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
        $base = '#/webhooks/' . $this->name;

        return $append !== '' ? $base . '/' . $append : $base;
    }
}
