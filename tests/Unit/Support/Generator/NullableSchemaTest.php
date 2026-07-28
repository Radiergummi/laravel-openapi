<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;

uses()->group('openapi');

// region Plain-type branch: widen a scalar type to an array including 'null'

it('widens a plain string schema to type: [string, null] (Bug 1)', function (): void {
    $schema = new OA\Schema(['type' => 'string']);
    $result = NullableSchema::wrap($schema);

    expect($result->type)
        ->toBe(['string', 'null'])
        ->and($result->ref)->toBe(Generator::UNDEFINED);
});

it('widens a plain integer schema to type: [integer, null]', function (): void {
    $schema = new OA\Schema(['type' => 'integer']);
    $result = NullableSchema::wrap($schema);

    expect($result->type)->toBe(['integer', 'null']);
});

it('adds null to an already-array type without duplicating', function (): void {
    $schema = new OA\Schema(['type' => ['string', 'number']]);
    $result = NullableSchema::wrap($schema);

    expect($result->type)->toBe(['string', 'number', 'null']);
});

it('does not duplicate null when the type array already contains it', function (): void {
    $schema = new OA\Schema(['type' => ['string', 'null']]);
    $result = NullableSchema::wrap($schema);

    expect($result->type)->toBe(['string', 'null']);
});

// endregion

// region No-mutation contract: wrap() must never modify the input object

it('does not mutate the input schema for the scalar branch', function (): void {
    $schema = new OA\Schema(['type' => 'string']);
    NullableSchema::wrap($schema);

    expect($schema->type)->toBe('string');
});

it('does not mutate the input schema for the type-array branch', function (): void {
    $schema = new OA\Schema(['type' => ['string', 'number']]);
    NullableSchema::wrap($schema);

    expect($schema->type)->toBe(['string', 'number']);
});

// endregion

// region $ref branch: wrap in oneOf so extra keywords are not silently ignored

it('wraps a $ref schema in oneOf with a null sibling (Bug 1)', function (): void {
    $schema = new OA\Schema(['ref' => '#/components/schemas/MyModel']);
    $result = NullableSchema::wrap($schema);

    expect($result->ref)
        ->toBe(Generator::UNDEFINED)
        ->and($result->oneOf)->toHaveCount(2);

    $refBranch = collect($result->oneOf)->first(fn($s) => $s->ref !== Generator::UNDEFINED);
    $nullBranch = collect($result->oneOf)->first(fn($s) => $s->type === 'null');

    expect($refBranch?->ref)
        ->toBe('#/components/schemas/MyModel')
        ->and($nullBranch?->type)->toBe('null');
});

// endregion

// region applyTo(): in-place nullability mirrors wrap()

it('applyTo wraps a $ref schema in oneOf instead of writing type: [null]', function (): void {
    $target = new OA\Schema(['ref' => '#/components/schemas/MyModel']);
    NullableSchema::applyTo($target);

    expect($target->ref)
        ->toBe(Generator::UNDEFINED)
        ->and($target->type)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toHaveCount(2);

    $refBranch = collect($target->oneOf)->first(fn($s) => $s->ref !== Generator::UNDEFINED);
    $nullBranch = collect($target->oneOf)->first(fn($s) => $s->type === 'null');

    expect($refBranch?->ref)
        ->toBe('#/components/schemas/MyModel')
        ->and($nullBranch?->type)->toBe('null');
});

it('applyTo migrates object structural keywords into the oneOf inner schema', function (): void {
    $target = new OA\Schema([
        'type' => 'object',
        'properties' => [new OA\Property(['property' => 'id', 'type' => 'integer'])],
        'required' => ['id'],
    ]);
    NullableSchema::applyTo($target);

    expect($target->type)
        ->toBe(Generator::UNDEFINED)
        ->and($target->properties)->toBe(Generator::UNDEFINED)
        ->and($target->required)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toHaveCount(2);

    $objectBranch = collect($target->oneOf)->first(fn($s) => $s->type === 'object');

    expect($objectBranch?->properties)
        ->toHaveCount(1)
        ->and($objectBranch?->required)->toBe(['id']);
});

it('applyTo migrates array validation constraints into the oneOf inner schema (#279)', function (): void {
    $target = new OA\Schema([
        'type' => 'array',
        'items' => new OA\Items(['type' => 'string']),
        'minItems' => 1,
        'maxItems' => 10,
        'uniqueItems' => true,
    ]);
    NullableSchema::applyTo($target);

    expect($target->type)
        ->toBe(Generator::UNDEFINED)
        ->and($target->items)->toBe(Generator::UNDEFINED)
        ->and($target->minItems)->toBe(Generator::UNDEFINED)
        ->and($target->maxItems)->toBe(Generator::UNDEFINED)
        ->and($target->uniqueItems)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toHaveCount(2);

    $arrayBranch = collect($target->oneOf)->first(fn($s) => $s->type === 'array');

    expect($arrayBranch?->minItems)
        ->toBe(1)
        ->and($arrayBranch?->maxItems)->toBe(10)
        ->and($arrayBranch?->uniqueItems)->toBeTrue();
});

it('applyTo migrates numeric and string constraints into the oneOf inner schema (#279)', function (): void {
    $target = new OA\Schema([
        'type' => 'object',
        'minimum' => 1,
        'maximum' => 100,
        'exclusiveMinimum' => 0,
        'exclusiveMaximum' => 101,
        'multipleOf' => 5,
        'minLength' => 2,
        'maxLength' => 8,
        'pattern' => '^a',
        'format' => 'int32',
        'enum' => ['a', 'b'],
    ]);
    NullableSchema::applyTo($target);

    expect($target->minimum)
        ->toBe(Generator::UNDEFINED)
        ->and($target->maximum)->toBe(Generator::UNDEFINED)
        ->and($target->exclusiveMinimum)->toBe(Generator::UNDEFINED)
        ->and($target->exclusiveMaximum)->toBe(Generator::UNDEFINED)
        ->and($target->multipleOf)->toBe(Generator::UNDEFINED)
        ->and($target->minLength)->toBe(Generator::UNDEFINED)
        ->and($target->maxLength)->toBe(Generator::UNDEFINED)
        ->and($target->pattern)->toBe(Generator::UNDEFINED)
        ->and($target->format)->toBe(Generator::UNDEFINED)
        ->and($target->enum)->toBe(Generator::UNDEFINED);

    $objectBranch = collect($target->oneOf)->first(fn($s) => $s->type === 'object');

    expect($objectBranch?->minimum)
        ->toBe(1)
        ->and($objectBranch?->maximum)->toBe(100)
        ->and($objectBranch?->exclusiveMinimum)->toBe(0)
        ->and($objectBranch?->exclusiveMaximum)->toBe(101)
        ->and($objectBranch?->multipleOf)->toBe(5)
        ->and($objectBranch?->minLength)->toBe(2)
        ->and($objectBranch?->maxLength)->toBe(8)
        ->and($objectBranch?->pattern)->toBe('^a')
        ->and($objectBranch?->format)->toBe('int32')
        ->and($objectBranch?->enum)->toBe(['a', 'b']);
});

it('applyTo keeps description and example on the outer schema (#279)', function (): void {
    $target = new OA\Schema([
        'type' => 'array',
        'items' => new OA\Items(['type' => 'string']),
        'minItems' => 1,
        'description' => 'A list',
        'example' => ['x'],
    ]);
    NullableSchema::applyTo($target);

    expect($target->description)
        ->toBe('A list')
        ->and($target->example)->toBe(['x'])
        ->and($target->minItems)->toBe(Generator::UNDEFINED);

    $arrayBranch = collect($target->oneOf)->first(fn($s) => $s->type === 'array');

    expect($arrayBranch?->minItems)->toBe(1);
});

it('applyTo widens a scalar type in place', function (): void {
    $target = new OA\Schema(['type' => 'string']);
    NullableSchema::applyTo($target);

    expect($target->type)->toBe(['string', 'null']);
});

// endregion

// region Fallback branch: schemas without a plain type are wrapped in oneOf

it('wraps a schema with no explicit type in oneOf with a null sibling', function (): void {
    // A schema that has e.g., oneOf but no top-level type or ref.
    $inner = new OA\Schema(['type' => 'string']);
    $schema = new OA\Schema(['oneOf' => [$inner]]);
    $result = NullableSchema::wrap($schema);

    $nullBranch = collect($result->oneOf)->first(fn($s) => $s->type === 'null');

    expect($result->oneOf)
        ->toHaveCount(2)
        ->and($nullBranch?->type)->toBe('null');
});

// endregion
// region wrap() / applyTo() agreement: the two are one implementation

dataset('nullability cases', [
    'scalar type' => [fn() => new OA\Schema(['type' => 'string'])],
    'all-scalar type array' => [fn() => new OA\Schema(['type' => ['string', 'number']])],
    'mixed type array' => [fn() => new OA\Schema(['type' => ['string', 'object']])],
    'empty type array' => [fn() => new OA\Schema(['type' => []])],
    'empty type array beside a constraint' => [fn() => new OA\Schema([
        'type' => [],
        'minLength' => 3,
    ])],
    'type array already nullable' => [fn() => new OA\Schema(['type' => ['object', 'null']])],
    'structured type' => [fn() => new OA\Schema([
        'type' => 'object',
        'properties' => [new OA\Property(['property' => 'id', 'type' => 'integer'])],
        'description' => 'A thing',
    ])],
    '$ref' => [fn() => new OA\Schema([
        'ref' => '#/components/schemas/MyModel',
        'description' => 'A thing',
    ])],
    'undefined type' => [fn() => new OA\Schema(['description' => 'A thing'])],
    // 'null' is the one OAS type that is neither scalar-widenable nor array/object; the output
    // itself is pinned by the idempotence tests below, since agreement alone permits nonsense.
    'null type' => [fn() => new OA\Schema(['type' => 'null'])],
    'already split into a nullable oneOf' => [fn() => new OA\Schema(['oneOf' => [
        new OA\Schema(['ref' => '#/components/schemas/MyModel']),
        new OA\Schema(['type' => 'null']),
    ]])],
]);

it('produces the same document from wrap() and applyTo()', function (Closure $build): void {
    $applied = $build();
    NullableSchema::applyTo($applied);

    expect(json_encode($applied))->toBe(json_encode(NullableSchema::wrap($build())));
})->with('nullability cases');

// endregion

// region Documentation keywords stay outside the wrapper

it('keeps the description on the outer node of a nullable $ref', function (): void {
    $target = new OA\Schema([
        'ref' => '#/components/schemas/MyModel',
        'description' => 'The owning account.',
    ]);
    NullableSchema::applyTo($target);

    expect($target->description)
        ->toBe('The owning account.')
        ->and($target->ref)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf[0]->ref)->toBe('#/components/schemas/MyModel')
        ->and($target->oneOf[0]->description)->toBe(Generator::UNDEFINED);
});

it('keeps the description when wrap() splits a $ref', function (): void {
    $result = NullableSchema::wrap(new OA\Schema([
        'ref' => '#/components/schemas/MyModel',
        'description' => 'The owning account.',
    ]));

    expect($result->description)
        ->toBe('The owning account.')
        ->and($result->oneOf[0]->ref)->toBe('#/components/schemas/MyModel');
});

it('keeps a default on the outer node rather than scoping it to the non-null branch', function (): void {
    $target = new OA\Schema(['type' => 'object', 'properties' => [], 'default' => ['a' => 1]]);
    NullableSchema::applyTo($target);

    expect($target->default)
        ->toBe(['a' => 1])
        ->and($target->oneOf[0]->default)->toBe(Generator::UNDEFINED);
});

// endregion

// region Undefined type: an unconstrained schema already permits null

it('leaves a schema carrying nothing but documentation untouched', function (): void {
    $target = new OA\Schema(['description' => 'Anything at all.']);
    NullableSchema::applyTo($target);

    // oneOf: [{}, {type: 'null'}] would reject null: it matches both branches, so exactly-one fails.
    expect($target->oneOf)
        ->toBe(Generator::UNDEFINED)
        ->and($target->type)->toBe(Generator::UNDEFINED)
        ->and($target->description)->toBe('Anything at all.');
});

it('wraps a typeless schema that does carry a constraint', function (): void {
    $target = new OA\Schema(['enum' => ['a', 'b']]);
    NullableSchema::applyTo($target);

    expect($target->enum)
        ->toBe(Generator::UNDEFINED)
        ->and($target->oneOf[0]->enum)->toBe(['a', 'b'])
        ->and($target->oneOf[1]->type)->toBe('null');
});

// endregion

// region Empty type array: neither widened nor split, both of which yield a null-only schema

it('leaves a schema whose type is an empty array untouched', function (): void {
    $target = new OA\Schema(['type' => []]);
    NullableSchema::applyTo($target);

    // Widening would write type: ['null'], admitting only null. Splitting is no better: the empty
    // type array vanishes inside the wrapper, leaving oneOf: [{}, {type: 'null'}], whose first
    // branch matches null too, so exactly-one fails and null is the one value rejected.
    expect($target->type)
        ->toBe([])
        ->and($target->oneOf)->toBe(Generator::UNDEFINED);
});

it('leaves an empty type array untouched through wrap() as well', function (): void {
    $schema = new OA\Schema(['type' => []]);
    $result = NullableSchema::wrap($schema);

    expect($result->type)
        ->toBe([])
        ->and($result->oneOf)->toBe(Generator::UNDEFINED)
        ->and($schema->type)->toBe([]);
});

it('keeps documentation on a schema whose type is an empty array', function (): void {
    $target = new OA\Schema(['type' => [], 'description' => 'Anything at all.']);
    NullableSchema::applyTo($target);

    expect($target->type)
        ->toBe([])
        ->and($target->oneOf)->toBe(Generator::UNDEFINED)
        ->and($target->description)->toBe('Anything at all.');
});

it('still splits when an empty type array accompanies a real constraint', function (): void {
    $target = new OA\Schema(['type' => [], 'minLength' => 3]);
    NullableSchema::applyTo($target);

    // The empty type array justifies no wrapper of its own, but it must not suppress one either.
    expect($target->minLength)
        ->toBe(Generator::UNDEFINED)
        ->and($target->type)->toBe([])
        ->and($target->oneOf)->toHaveCount(2)
        ->and($target->oneOf[0]->minLength)->toBe(3)
        ->and($target->oneOf[1]->type)->toBe('null');
});

// endregion

// region Emitted document: what a consumer sees, not just the annotation object

// A 3.1 context is what makes these assertions mean anything: without one, swagger-php serialises
// 3.0-style and drops a multi-member type array entirely, so every candidate output collapses to the
// same bytes and the assertion stops discriminating.

it('emits an empty type array unchanged in a 3.1 document', function (): void {
    $target = new OA\Schema(['type' => [], '_context' => new Context(['version' => '3.1.0'])]);
    NullableSchema::applyTo($target);

    // Both near misses are visible here: widening emits {"type":["null"]} and splitting emits
    // {"oneOf":[{},{"type":"null"}]}.
    expect(json_encode($target))->toBe('{"type":[]}');
});

it('emits the empty type array on the outer node of a nullable $ref (existing behaviour)', function (): void {
    $target = new OA\Schema([
        'ref' => '#/components/schemas/MyModel',
        'type' => [],
        '_context' => new Context(['version' => '3.1.0']),
    ]);
    NullableSchema::applyTo($target);

    // Pins what happens today rather than what should: a $ref always splits, so the widening guard
    // never runs and the empty type array stays outside the wrapper instead of vanishing inside it,
    // adding a stray keyword a plain nullable $ref does not carry. Consistent with the composite
    // case above, and unreachable from any producer, so it is recorded rather than chased.
    expect(json_encode($target))
        ->toBe('{"type":[],"oneOf":[{"$ref":"#\/components\/schemas\/MyModel"},{"type":"null"}]}');
});

// endregion

// region Type-array boundaries either side of the widening predicate

it('widens a single-member scalar type array', function (): void {
    $target = new OA\Schema(['type' => ['string']]);
    NullableSchema::applyTo($target);

    expect($target->type)
        ->toBe(['string', 'null'])
        ->and($target->oneOf)->toBe(Generator::UNDEFINED);
});

it('splits a type array mixing a scalar with a structured type', function (): void {
    $target = new OA\Schema(['type' => ['string', 'object']]);
    NullableSchema::applyTo($target);

    expect($target->type)
        ->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toHaveCount(2)
        ->and($target->oneOf[0]->type)->toBe(['string', 'object'])
        ->and($target->oneOf[1]->type)->toBe('null');
});

it('splits a non-scalar plain type rather than widening it', function (): void {
    $target = new OA\Schema(['type' => 'object', 'minProperties' => 1]);
    NullableSchema::applyTo($target);

    expect($target->type)
        ->toBe(Generator::UNDEFINED)
        ->and($target->oneOf[0]->type)->toBe('object')
        ->and($target->oneOf[0]->minProperties)->toBe(1)
        ->and($target->oneOf[1]->type)->toBe('null');
});

// endregion

// region Idempotence: applying the rule twice must not nest the wrapper

it('leaves a schema already split into a nullable oneOf alone', function (): void {
    $target = new OA\Schema(['oneOf' => [
        new OA\Schema(['ref' => '#/components/schemas/MyModel']),
        new OA\Schema(['type' => 'null']),
    ]]);
    NullableSchema::applyTo($target);

    // Nesting the split would make null match both outer branches, so exactly-one fails.
    expect($target->oneOf)
        ->toHaveCount(2)
        ->and($target->oneOf[0]->ref)->toBe('#/components/schemas/MyModel')
        ->and($target->oneOf[1]->type)->toBe('null');
});

it('leaves a schema already split into a nullable anyOf alone', function (): void {
    $target = new OA\Schema(['anyOf' => [
        new OA\Schema(['type' => 'string']),
        new OA\Schema(['type' => 'null']),
    ]]);
    NullableSchema::applyTo($target);

    expect($target->anyOf)
        ->toHaveCount(2)
        ->and($target->oneOf)->toBe(Generator::UNDEFINED);
});

it('leaves an explicit null type alone rather than splitting it into two null branches', function (): void {
    $target = new OA\Schema(['type' => 'null']);
    NullableSchema::applyTo($target);

    expect($target->type)
        ->toBe('null')
        ->and($target->oneOf)->toBe(Generator::UNDEFINED);
});

it('leaves an already-nullable type array alone', function (): void {
    $target = new OA\Schema(['type' => ['object', 'null']]);
    NullableSchema::applyTo($target);

    expect($target->type)
        ->toBe(['object', 'null'])
        ->and($target->oneOf)->toBe(Generator::UNDEFINED);
});

it('still splits a composed schema whose branches are not nullable', function (): void {
    $target = new OA\Schema(['oneOf' => [
        new OA\Schema(['type' => 'string']),
        new OA\Schema(['type' => 'integer']),
    ]]);
    NullableSchema::applyTo($target);

    expect($target->oneOf)
        ->toHaveCount(2)
        ->and($target->oneOf[0]->oneOf)->toHaveCount(2)
        ->and($target->oneOf[1]->type)->toBe('null');
});

// endregion

// region No-mutation contract on the branch that rewrites the most

it('does not mutate the input schema for the structured branch', function (): void {
    $items = new OA\Items(['type' => 'string']);
    $schema = new OA\Schema(['type' => 'array', 'items' => $items, 'minItems' => 1]);

    NullableSchema::wrap($schema);

    expect($schema->type)
        ->toBe('array')
        ->and($schema->items)->toBe($items)
        ->and($schema->minItems)->toBe(1)
        ->and($schema->oneOf)->toBe(Generator::UNDEFINED);
});

// endregion
