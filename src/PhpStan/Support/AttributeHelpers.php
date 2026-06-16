<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Support;

use PhpParser\Node;
use ReflectionClass;

use function array_find;
use function class_exists;

/**
 * Shared helpers for PHPStan attribute rules.
 *
 * Resolves attribute arguments by name, falling back to the positional slot in the attribute's
 * constructor. The slot is derived via reflection, so it survives constructor reorders.
 */
final class AttributeHelpers
{
    /** @var array<string, array<string, int>> */
    private static array $parameterPositions = [];

    /** True when the argument is present (by name or positional slot) with a non-null value. */
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
     * Returns the argument node resolved by name, falling back to the positional slot. Returns null
     * when not passed either way. A literal `null` is still returned; use {@see argumentIsProvided()}
     * to treat it as absent.
     */
    public static function getArgument(Node\Attribute $attribute, string $name): ?Node\Arg
    {
        $named = array_find(
            $attribute->args,
            static fn(Node\Arg $argument): bool => $argument->name !== null && $argument->name->toString() === $name,
        );

        if ($named !== null) {
            return $named;
        }

        $position = self::parameterPosition($attribute->name->toString(), $name);

        if ($position === null) {
            return null;
        }

        // Positional arguments always precede named ones and appear in source order, so the
        // parameter's slot is the n-th argument carrying no explicit name.
        $positionalIndex = 0;

        foreach ($attribute->args as $argument) {
            if ($argument->name !== null) {
                break;
            }

            if ($positionalIndex === $position) {
                return $argument;
            }

            ++$positionalIndex;
        }

        return null;
    }

    /** Constructor position of the given parameter, or null when unavailable. Memoized per class. */
    private static function parameterPosition(string $attributeClass, string $parameterName): ?int
    {
        if (!isset(self::$parameterPositions[$attributeClass])) {
            self::$parameterPositions[$attributeClass] = self::resolveParameterPositions($attributeClass);
        }

        return self::$parameterPositions[$attributeClass][$parameterName] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private static function resolveParameterPositions(string $attributeClass): array
    {
        if (!class_exists($attributeClass)) {
            return [];
        }

        $constructor = new ReflectionClass($attributeClass)->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $positions = [];

        foreach ($constructor->getParameters() as $index => $parameter) {
            $positions[$parameter->getName()] = $index;
        }

        return $positions;
    }

    /**
     * @param list<Node\AttributeGroup> $attrGroups
     *
     * @return list<Node\Attribute>
     */
    public static function attributesNamed(array $attrGroups, string $fqn): array
    {
        return self::attributesByFqn($attrGroups)[$fqn] ?? [];
    }

    /**
     * Buckets every attribute by FQN so one pass serves multiple FQN lookups.
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
     * Returns the `attrGroups` of any declaration node, or `[]` for nodes with no attribute groups.
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
