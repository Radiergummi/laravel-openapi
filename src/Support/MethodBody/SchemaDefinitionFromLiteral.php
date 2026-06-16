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
 * Maps literal AST expressions onto plain JSON-Schema definition arrays.
 *
 * Shared by the inline-JSON response scan and the Resource `toArray()` reader.
 * Keyed arrays become objects, unkeyed or sequential-integer-keyed become lists,
 * scalars map to their JSON type, and dynamic values become empty (unconstrained) definitions.
 * A dynamic key, spread, or mixed keyed/unkeyed array throws {@see NonLiteralValueException}.
 *
 * @internal
 */
final readonly class SchemaDefinitionFromLiteral
{
    /**
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
        // them, using the same `array_is_list` semantics as the evaluated-literal path.
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
     * An array with unknown item schema. `items` is always present: swagger-php rejects
     * `type: array` without `@OA\Items` and `openapi:generate` validates by default.
     *
     * @return array{type: 'array', items: array<never, never>}
     */
    private static function arrayOfUnknownItems(): array
    {
        return ['type' => 'array', 'items' => []];
    }

    /**
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
     * Derives item schema from the first element; falls back to unconstrained items on type or
     * property-shape mismatch.
     *
     * @param non-empty-list<array<string, mixed>> $definitions
     *
     * @return array<string, mixed>
     */
    private static function listDefinition(array $definitions): array
    {
        $first = $definitions[0];
        $firstIsObject = ($first['type'] ?? null) === 'object';

        foreach ($definitions as $definition) {
            if (($definition['type'] ?? null) !== ($first['type'] ?? null)) {
                return self::arrayOfUnknownItems();
            }

            if ($firstIsObject && ($definition['properties'] ?? null) !== ($first['properties'] ?? null)) {
                return self::arrayOfUnknownItems();
            }
        }

        return $first === []
            ? self::arrayOfUnknownItems()
            : ['type' => 'array', 'items' => $first];
    }
}
