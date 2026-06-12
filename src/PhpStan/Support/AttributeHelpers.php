<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Support;

use PhpParser\Node;

/**
 * Shared helpers for PHPStan attribute rules. Operates directly on PhpParser AST nodes — no
 * reflection, no scope lookups — so callers can use these from any rule's `processNode()`.
 *
 * Lookups are by named argument only. Positional argument resolution is deliberately omitted: the
 * positions would have to be hardcoded per attribute (fragile under constructor reorders) or
 * resolved via reflection (extra complexity for a corner case). Authoring attributes carry
 * 2–6 parameters, so positional use beyond the first argument is vanishingly rare; rules that
 * miss `#[Example('n', 'v')]`-style positional misuse still get caught by the runtime constructor.
 */
final class AttributeHelpers
{
    /**
     * True when the named argument is present with a non-null value. A literal `null` is treated
     * as absent, matching `?T = null` attribute parameter defaults.
     */
    public static function argumentIsProvided(Node\Attribute $attribute, string $name): bool
    {
        $argument = self::getArgument($attribute, $name);

        if ($argument === null) {
            return false;
        }

        $value = $argument->value;

        return !($value instanceof Node\Expr\ConstFetch && $value->name->toLowerString() === 'null');
    }

    /**
     * Returns the argument node for the given named argument, or null when the argument was not
     * passed. A literal `null` is still returned — callers that want to treat it as absent should
     * use {@see argumentIsProvided()} or inspect the returned value themselves.
     */
    public static function getArgument(Node\Attribute $attribute, string $name): ?Node\Arg
    {
        return array_find(
            $attribute->args,
            fn($argument) => $argument->name !== null && $argument->name->toString() === $name,
        );
    }

    /**
     * Collects every `#[$fqn]` attribute node attached to the given attribute groups. Names are
     * compared as fully-qualified strings (PHPStan resolves names before invoking rules).
     *
     * @param list<Node\AttributeGroup> $attrGroups
     *
     * @return list<Node\Attribute>
     */
    public static function attributesNamed(array $attrGroups, string $fqn): array
    {
        return self::attributesByFqn($attrGroups)[$fqn] ?? [];
    }

    /**
     * Buckets every attribute attached to the given groups by its fully-qualified name. Lets a
     * single pass over `attrGroups` serve any number of subsequent FQN lookups in one rule —
     * cheaper than calling {@see attributesNamed()} for each FQN of interest.
     *
     * @param list<Node\AttributeGroup> $attrGroups
     *
     * @return array<string, list<Node\Attribute>>
     */
    public static function attributesByFqn(array $attrGroups): array
    {
        $buckets = [];

        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $buckets[$attribute->name->toString()][] = $attribute;
            }
        }

        return $buckets;
    }

    /**
     * Returns the `attrGroups` of any declaration node that carries attributes (ClassMethod,
     * Class_, Function_, Property, Param, ClassConst, …). Returns an empty list for nodes that
     * have no attribute groups, sparing callers a property_exists guard at each call site.
     *
     * @return list<Node\AttributeGroup>
     */
    public static function getAttributeGroups(Node $node): array
    {
        if (!property_exists($node, 'attrGroups')) {
            return [];
        }

        /** @var list<Node\AttributeGroup> */
        return $node->attrGroups;
    }
}
