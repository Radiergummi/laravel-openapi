<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;

use function array_is_list;
use function array_map;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Maps literal AST expressions onto plain JSON-Schema definition arrays — the shared
 * value-typing half of the Tier-1 body scans (epic #5). The inline-JSON response scan (#14) and
 * the Resource `toArray()` reader (#12) both walk array literals whose values are typed by the
 * same rules:
 *
 * - nested literal arrays recurse (keyed → object, unkeyed or sequential-integer-keyed → list),
 * - literal scalars map to their JSON type,
 * - a dynamic *value* stays an unconstrained (empty) definition — dropping a response property
 *   would be silently wrong for spec consumers,
 * - a dynamic *key*, a spread entry, or a keyed/unkeyed mix throws
 *   {@see NonLiteralValueException} — the surrounding structure is unknowable, so callers
 *   degrade their whole match.
 *
 * Definition arrays convert to `OA\Schema` via `Support\Generator\SchemaFromArrayDefinition`.
 *
 * @internal
 */
final readonly class SchemaDefinitionFromLiteral
{
    /**
     * Walks a literal array AST node into a schema definition. A dynamic value under a literal
     * key — including a nested array that is itself unreadable — keeps the property with an
     * unconstrained schema; a dynamic key, a spread entry, or a keyed/unkeyed mix throws.
     *
     * @return array<string, mixed>
     *
     * @throws NonLiteralValueException
     */
    public static function fromArrayNode(Array_ $expression): array
    {
        /** @var array<array<string, mixed>> $properties Definitions under PHP's native key coercion. */
        $properties = [];

        /** @var list<Expr> $elements */
        $elements = [];

        foreach ($expression->items as $item) {
            if ($item->unpack) {
                throw NonLiteralValueException::for($expression);
            }

            if ($item->key === null) {
                $elements[] = $item->value;

                continue;
            }

            $key = AstLiteralEvaluator::evaluate($item->key);

            if (!is_int($key) && !is_string($key)) {
                throw NonLiteralValueException::for($item->key);
            }

            $properties[$key] = self::fromValue($item->value);
        }

        // A keyed/unkeyed mix does not map onto one JSON shape.
        if ($properties !== [] && $elements !== []) {
            throw NonLiteralValueException::for($expression);
        }

        if ($elements !== []) {
            return self::listDefinition(array_map(self::fromValue(...), $elements));
        }

        // Explicit sequential integer keys are a JSON array, exactly as `json_encode` treats
        // them — the same `array_is_list` semantics as the evaluated-literal path.
        if ($properties !== [] && array_is_list($properties)) {
            return self::listDefinition($properties);
        }

        $objectProperties = [];

        foreach ($properties as $key => $definition) {
            $objectProperties[(string) $key] = $definition;
        }

        return ['type' => 'object', 'properties' => $objectProperties];
    }

    /**
     * The definition for one property value: nested literal arrays recurse, literal scalars map
     * to their JSON type, and anything dynamic stays as an unconstrained (empty) schema rather
     * than dropping the property.
     *
     * @return array<string, mixed>
     */
    public static function fromValue(Expr $value): array
    {
        if ($value instanceof Array_) {
            if ($value->items === []) {
                return self::arrayOfUnknownItems();
            }

            try {
                return self::fromArrayNode($value);
            } catch (NonLiteralValueException) {
                return [];
            }
        }

        try {
            $literal = AstLiteralEvaluator::evaluate($value);
        } catch (NonLiteralValueException) {
            return [];
        }

        return self::fromLiteralValue($literal);
    }

    /**
     * An array whose item schema is unknown — empty literals and heterogeneous or unreadable
     * lists. The `items` key is always present: swagger-php's validator rejects a `type: array`
     * without `@OA\Items` on both supported majors, and `openapi:generate` validates by default.
     *
     * @return array{type: 'array', items: array<never, never>}
     */
    private static function arrayOfUnknownItems(): array
    {
        return ['type' => 'array', 'items' => []];
    }

    /**
     * Maps an already-evaluated literal (a scalar, or an array reached through a class constant)
     * onto its schema definition, with the same semantics as the AST branch: `null` yields an
     * unconstrained definition, an empty array is an array of unknown items, and list items are
     * only claimed when every element agrees on a type (see {@see self::listDefinition()}).
     *
     * @return array<string, mixed>
     */
    public static function fromLiteralValue(mixed $literal): array
    {
        if (is_array($literal)) {
            if ($literal === []) {
                return self::arrayOfUnknownItems();
            }

            if (array_is_list($literal)) {
                return self::listDefinition(array_map(self::fromLiteralValue(...), $literal));
            }

            $properties = [];

            foreach ($literal as $key => $value) {
                $properties[(string) $key] = self::fromLiteralValue($value);
            }

            return ['type' => 'object', 'properties' => $properties];
        }

        return match (true) {
            is_string($literal) => ['type' => 'string'],
            is_bool($literal) => ['type' => 'boolean'],
            is_int($literal) => ['type' => 'integer'],
            is_float($literal) => ['type' => 'number'],
            default => [],
        };
    }

    /**
     * Items are derived from the first element; when a later element disagrees on type the items
     * stay unconstrained — a heterogeneous literal list has no single item schema.
     *
     * @param non-empty-list<array<string, mixed>> $definitions
     *
     * @return array<string, mixed>
     */
    private static function listDefinition(array $definitions): array
    {
        $first = $definitions[0];

        foreach ($definitions as $definition) {
            if (($definition['type'] ?? null) !== ($first['type'] ?? null)) {
                return self::arrayOfUnknownItems();
            }
        }

        return $first === []
            ? self::arrayOfUnknownItems()
            : ['type' => 'array', 'items' => $first];
    }
}
