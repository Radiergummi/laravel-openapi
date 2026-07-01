<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Types;

use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionClass;
use stdClass;

uses()->group('openapi');

/**
 * Characterization catalog for #480: every place the two former engines (phpstan-keyed
 * TypeNodeToSchema, symfony-keyed JsonSchemaFromType) disagreed is pinned here to the chosen
 * canonical output of the unified engine. Each `it()` is one divergence class from the plan; the
 * assertion is the deliberate verdict, not an accident of implementation.
 *
 * The unified engine is driven exactly as production drives it: a PHPDoc type string is bridged to a
 * symfony Type via {@see TypeNodeResolver::toType()}, then shaped by {@see JsonSchemaFromType}.
 */
function characterizationEngine(): JsonSchemaFromType
{
    return new JsonSchemaFromType(new NullLogger(), new ComponentSchemaRegistry());
}

/**
 * Resolves a PHPDoc type expression through the production path (toType → fromType), against the
 * stdClass reflection context. The leaf-class strategy maps any resolved FQCN to a sentinel `$ref`
 * so leaf delegation stays observable, mirroring the former TypeNodeToSchema test harness.
 *
 * @return array<string, mixed> the JSON-Schema array view, or ['__null__' => true] when the type
 *                              string is unresolvable (the unified engine's degrade signal)
 */
function unifiedSchemaArray(string $expression): array
{
    $type = new TypeNodeResolver()->toType(
        parsePhpDocType($expression),
        new ReflectionClass(stdClass::class),
    );

    if ($type === null) {
        return ['__null__' => true];
    }

    $schema = characterizationEngine()->fromType(
        $type,
        static fn(string $fqcn): OA\Schema => new OA\Schema(['ref' => "#/components/schemas/{$fqcn}"]),
    );

    return json_decode(json_encode($schema), associative: true);
}

// Class 1 — enum cases survive. Covered in TypeSchemaEnumParityTest (needs a real backed enum).

it('class 2: true/false pseudo-types map to boolean', function (): void {
    expect(unifiedSchemaArray('true'))->toBe(['type' => 'boolean'])
        ->and(unifiedSchemaArray('false'))->toBe(['type' => 'boolean']);
});

it('class 3: DateTime object formats to string/date-time (no leaf callback override)', function (): void {
    // Bridged through toType against a real class name.
    $type = new TypeNodeResolver()->toType(
        parsePhpDocType('\DateTimeImmutable'),
        new ReflectionClass(stdClass::class),
    );

    $schema = json_decode(json_encode(characterizationEngine()->fromType($type)), associative: true);

    expect($schema)->toBe(['type' => 'string', 'format' => 'date-time']);
});

it('class 4: array<string, T> is a map with additionalProperties', function (): void {
    expect(unifiedSchemaArray('array<string, int>'))
        ->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'integer']]);
});

it('class 5: an unresolvable leaf makes the whole type degrade to null', function (): void {
    // Canonical: the boundary returns null (caller falls back), rather than the former phpstan
    // engine's partial recovery. A single unverifiable class name fails the resolve.
    expect(unifiedSchemaArray('NotARealClassXyz'))->toBe(['__null__' => true])
        ->and(unifiedSchemaArray('array{known: int, mystery: NotARealClassXyz}'))
        ->toBe(['__null__' => true]);
});

it('class 6: a nullable type is wrapped exactly once', function (): void {
    // Asserted on the schema object (not the JSON dump): swagger-php flattens a top-level type array
    // in json_encode, but the OAS 3.1 idiom lives on the ->type property as ['integer', 'null'].
    $type = new TypeNodeResolver()->toType(
        parsePhpDocType('?int'),
        new ReflectionClass(stdClass::class),
    );

    $schema = characterizationEngine()->fromType($type);

    expect($schema->type)->toBe(['integer', 'null'])
        ->and($schema->nullable)->not->toBeTrue();
});

it('class 7: a multi-class union (A|B) becomes oneOf', function (): void {
    // The former phpstan engine returned null for a non-nullable multi-class union; canonical is the
    // symfony oneOf.
    $schema = unifiedSchemaArray('\stdClass|\Exception');

    expect($schema)->toHaveKey('oneOf')
        ->and($schema['oneOf'])->toHaveCount(2);
});

it('class 8: array<string, mixed> is a permissive map', function (): void {
    expect(unifiedSchemaArray('array<string, mixed>'))
        ->toBe(['type' => 'object', 'additionalProperties' => true]);
});

it('class 9: iterable<T> maps to a list', function (): void {
    expect(unifiedSchemaArray('iterable<string>'))
        ->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('class 10: exotic Type subclasses land on the non-crashing fallback', function (): void {
    // Intersection resolves to IntersectionType; the engine must not crash — it degrades to a note.
    $type = new TypeNodeResolver()->toType(
        parsePhpDocType('\stdClass&\Countable'),
        new ReflectionClass(stdClass::class),
    );

    $schema = json_decode(json_encode(characterizationEngine()->fromType($type)), associative: true);

    expect($schema['type'])->toBe('string')
        ->and($schema['description'])->toContain('Unmapped type');
});

it('class 11: self / static resolve via the context-aware boundary', function (): void {
    $type = new TypeNodeResolver()->toType(
        parsePhpDocType('self'),
        new ReflectionClass(stdClass::class),
    );

    // stdClass has no schema shape, but the point is the name resolved (non-null) rather than throwing.
    expect($type)->not->toBeNull();
});

it('class 12: array-shape properties serialize in sorted key order (ksort)', function (): void {
    // The former phpstan engine kept source order; canonical is symfony's ksort.
    $schema = unifiedSchemaArray('array{b: int, a: string}');

    expect(array_keys($schema['properties']))
        ->toBe(['a', 'b'])
        ->and($schema['required'])->toBe(['a', 'b']);
});

it('class 13: bare array maps to an untyped array', function (): void {
    expect(unifiedSchemaArray('array'))
        ->toBe(['type' => 'array', 'items' => []]);
});
