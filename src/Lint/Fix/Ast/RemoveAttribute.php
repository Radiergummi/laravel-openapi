<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * Removes one or more attribute applications from the target member, addressed by their position in
 * the member's flat, source-order attribute list (the same enumeration the fixer used when it
 * selected which attributes to drop). The visitor splices the named indices out of their groups and
 * drops any group it empties.
 *
 * @internal
 */
final readonly class RemoveAttribute extends AstOperation
{
    /**
     * @param list<int> $attributeIndices Positions within the member's flat, source-order attribute
     *                                    list. Order is irrelevant; the visitor removes descending.
     */
    public function __construct(
        TargetSelector $target,
        public array $attributeIndices,
    ) {
        parent::__construct($target);
    }
}
