<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

/**
 * Mutable intermediate node used by {@see ValidationRulesToSchema::process()} to assemble dotted
 * (`address.city`) and wildcard (`items.*.name`) validation keys into a nested tree before each
 * node is mapped to a {@see FieldDescriptor}. Keeping path-walking separate from rule-mapping lets
 * sibling paths merge naturally onto shared nodes.
 *
 * @internal
 */
final class RuleTreeNode
{
    /**
     * Normalised rules applying directly to this path (e.g. the `address` rule list for the
     * `address` node when both `address` and `address.city` are declared).
     *
     * @var list<object|string>
     */
    public array $ownRules = [];

    /**
     * Child object properties, keyed by path segment.
     *
     * @var array<string, RuleTreeNode>
     */
    public array $children = [];

    /**
     * Array element node, set when a `*` segment descends from this node.
     */
    public ?RuleTreeNode $items = null;
}
