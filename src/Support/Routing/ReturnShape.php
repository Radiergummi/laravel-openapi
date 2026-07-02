<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Radiergummi\OpenApi\Enums\PaginatorKind;
use Symfony\Component\TypeInfo\Type;

/**
 * The normalized, plugin-agnostic description of what a controller action returns, merged from its
 * native signature and `@return` PHPDoc. Response-side plugins consume this instead of each
 * re-deriving container kind, item type, nullability, and unions from raw reflection.
 *
 * The descriptor classifies *structure* only. It never answers "is this item class a JsonResource /
 * Data / a class I own"; that question stays with the plugin, which reads {@see $itemType} and
 * {@see $unionMembers} and applies its own `is_a` test.
 *
 * @internal
 */
final readonly class ReturnShape
{
    /**
     * @param ReturnContainer    $container     single value vs. list vs. paginated envelope
     * @param null|Type          $itemType      for {@see ReturnContainer::Single} the whole return
     *                                          type; for {@see ReturnContainer::ListOf} /
     *                                          {@see ReturnContainer::Paginated} the element type;
     *                                          null when it cannot be resolved
     * @param null|PaginatorKind $paginatorKind set only when $container is {@see ReturnContainer::Paginated}
     * @param bool               $nullable      whether the return may be null (`?T`, `T|null`)
     * @param list<Type>         $unionMembers  the resolved non-null members of a multi-class union
     *                                          return; empty for every non-union shape
     */
    public function __construct(
        public ReturnContainer $container,
        public ?Type $itemType,
        public ?PaginatorKind $paginatorKind,
        public bool $nullable,
        public array $unionMembers = [],
    ) {}
}
