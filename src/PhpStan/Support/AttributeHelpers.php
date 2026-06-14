<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Support;

use PhpParser\Node;
use ReflectionClass;

use function array_find;
use function class_exists;

/**
 * Shared helpers for PHPStan attribute rules. Operates on PhpParser AST nodes and resolves
 * arguments by name; when a named match is absent, the lookup falls back to the positional
 * argument occupying that parameter's constructor slot, so `#[Response(404, '…')]` resolves the
 * same as `#[Response(status: 404, …)]`.
 *
 * The positional slot is derived by reflecting the attribute's constructor (the attribute name is
 * already resolved to a real class by the time rules run), so it stays correct across constructor
 * reorders rather than depending on a hardcoded index. Resolution runs only under PHPStan, never
 * at application runtime.
 */
final class AttributeHelpers
{
    /**
     * Per-attribute-class map of constructor parameter name → positional index, memoized across
     * lookups within a single analysis process.
     *
     * @var array<string, array<string, int>>
     */
    private static array $parameterPositions = [];

    /**
     * True when the argument is present (by name or positional slot) with a non-null value. A
     * literal `null` is treated as absent, matching `?T = null` attribute parameter defaults.
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
     * Returns the argument node for the given parameter, resolved by name and falling back to the
     * positional slot that parameter occupies in the attribute's constructor. Returns null when the
     * argument was passed neither way. A literal `null` is still returned — callers that want to
     * treat it as absent should use {@see argumentIsProvided()} or inspect the returned value.
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

    /**
     * Constructor position of the given parameter on the attribute class, or null when the class
     * is unavailable or has no such parameter. Results are memoized per class.
     */
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
