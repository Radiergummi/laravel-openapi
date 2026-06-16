<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

/**
 * Intermediate node used by {@see ValidationRulesToSchema::process()} to assemble dotted
 * and wildcard validation keys into a tree before mapping each node to a {@see FieldDescriptor}.
 *
 * @internal
 */
final class RuleTreeNode
{
    /**
     * Rules applying directly to this path segment.
     *
     * @var list<object|string>
     */
    public array $ownRules = [];

    /** @var array<string, RuleTreeNode> */
    public array $children = [];

    /**
     * Array element node, set when a `*` segment descends from this node.
     */
    public ?RuleTreeNode $items = null;
}
