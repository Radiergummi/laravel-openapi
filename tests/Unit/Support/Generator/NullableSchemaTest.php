<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;

uses()->group('openapi');

// region Plain-type branch: widen a scalar type to an array including 'null'

it('widens a plain string schema to type: [string, null] (Bug 1)', function (): void {
    $schema = new OA\Schema(['type' => 'string']);
    $result = NullableSchema::wrap($schema);

    expect($result->type)->toBe(['string', 'null'])
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

    expect($result->ref)->toBe(Generator::UNDEFINED)
        ->and($result->oneOf)->toHaveCount(2);

    $refBranch = collect($result->oneOf)->first(fn($s) => $s->ref !== Generator::UNDEFINED);
    $nullBranch = collect($result->oneOf)->first(fn($s) => $s->type === 'null');

    expect($refBranch?->ref)->toBe('#/components/schemas/MyModel')
        ->and($nullBranch?->type)->toBe('null');
});

// endregion

// region applyTo(): in-place nullability mirrors wrap()

it('applyTo wraps a $ref schema in oneOf instead of writing type: [null]', function (): void {
    $target = new OA\Schema(['ref' => '#/components/schemas/MyModel']);
    NullableSchema::applyTo($target);

    expect($target->ref)->toBe(Generator::UNDEFINED)
        ->and($target->type)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toHaveCount(2);

    $refBranch = collect($target->oneOf)->first(fn($s) => $s->ref !== Generator::UNDEFINED);
    $nullBranch = collect($target->oneOf)->first(fn($s) => $s->type === 'null');

    expect($refBranch?->ref)->toBe('#/components/schemas/MyModel')
        ->and($nullBranch?->type)->toBe('null');
});

it('applyTo migrates object structural keywords into the oneOf inner schema', function (): void {
    $target = new OA\Schema([
        'type' => 'object',
        'properties' => [new OA\Property(['property' => 'id', 'type' => 'integer'])],
        'required' => ['id'],
    ]);
    NullableSchema::applyTo($target);

    expect($target->type)->toBe(Generator::UNDEFINED)
        ->and($target->properties)->toBe(Generator::UNDEFINED)
        ->and($target->required)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toHaveCount(2);

    $objectBranch = collect($target->oneOf)->first(fn($s) => $s->type === 'object');

    expect($objectBranch?->properties)->toHaveCount(1)
        ->and($objectBranch?->required)->toBe(['id']);
});

it('applyTo widens a scalar type in place', function (): void {
    $target = new OA\Schema(['type' => 'string']);
    NullableSchema::applyTo($target);

    expect($target->type)->toBe(['string', 'null']);
});

// endregion

// region Fallback branch: schemas without a plain type are wrapped in oneOf

it('wraps a schema with no explicit type in oneOf with a null sibling', function (): void {
    // A schema that has e.g. oneOf but no top-level type or ref.
    $inner = new OA\Schema(['type' => 'string']);
    $schema = new OA\Schema(['oneOf' => [$inner]]);
    $result = NullableSchema::wrap($schema);

    $nullBranch = collect($result->oneOf)->first(fn($s) => $s->type === 'null');

    expect($result->oneOf)->toHaveCount(2)
        ->and($nullBranch?->type)->toBe('null');
});

// endregion
