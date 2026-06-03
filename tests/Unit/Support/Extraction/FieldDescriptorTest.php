<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Extraction\FieldDescriptor;

function isUndefined(mixed $value): bool
{
    return Generator::isDefault($value);
}

/**
 * @param array<OA\Schema> $branches
 */
function nonNullBranch(array $branches): OA\Schema
{
    foreach ($branches as $branch) {
        if ($branch->type !== 'null') {
            return $branch;
        }
    }

    throw new RuntimeException('expected a non-null oneOf branch');
}

/**
 * @param array<OA\Schema> $branches
 */
function nullBranch(array $branches): OA\Schema
{
    foreach ($branches as $branch) {
        if ($branch->type === 'null') {
            return $branch;
        }
    }

    throw new RuntimeException('expected a null oneOf branch');
}

uses()->group('openapi');

// region Bug 6: nullable object — properties/additionalProperties/required migration

it('wraps a nullable object with properties into oneOf, migrating properties (Bug 6)', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'object';
    $descriptor->nullable = true;

    $property = new OA\Property(['property' => 'name', 'type' => 'string']);
    $target = new OA\Schema([]);
    $target->type = 'object';
    $target->properties = [$property];

    $descriptor->applyTo($target);

    // Outer schema must use oneOf, not type
    expect($target->type)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);
    $null = nullBranch($target->oneOf);

    expect($inner->type)->toBe('object')
        ->and($inner->properties)->toBe([$property])
        ->and(isUndefined($target->properties))->toBeTrue()
        ->and($null->type)->toBe('null');
});

it('wraps a nullable object with required into oneOf, migrating required (Bug 6)', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'object';
    $descriptor->nullable = true;

    $target = new OA\Schema([]);
    $target->type = 'object';
    $target->required = ['name', 'email'];

    $descriptor->applyTo($target);

    expect($target->type)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);

    expect($inner->required)->toBe(['name', 'email'])
        ->and(isUndefined($target->required))->toBeTrue();
});

it('wraps a nullable object with additionalProperties into oneOf, migrating additionalProperties (Bug 6)', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'object';
    $descriptor->nullable = true;

    $additionalProps = new OA\AdditionalProperties(['type' => 'string']);
    $target = new OA\Schema([]);
    $target->type = 'object';
    $target->additionalProperties = $additionalProps;

    $descriptor->applyTo($target);

    expect($target->type)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);

    expect($inner->additionalProperties)->toBe($additionalProps)
        ->and(isUndefined($target->additionalProperties))->toBeTrue();
});

it('nullable array still migrates items into the inner oneOf schema', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'array';
    $descriptor->nullable = true;

    $items = new OA\Items(['type' => 'string']);
    $target = new OA\Schema([]);
    $target->type = 'array';
    $target->items = $items;

    $descriptor->applyTo($target);

    expect($target->type)->toBe(Generator::UNDEFINED)
        ->and($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);

    expect($inner->type)->toBe('array')
        ->and($inner->items)->toBe($items)
        ->and(isUndefined($target->items))->toBeTrue();
});

it('nullable scalar widens type array without oneOf wrapping', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';
    $descriptor->nullable = true;

    $target = new OA\Schema([]);
    $target->type = 'string';

    $descriptor->applyTo($target);

    expect($target->type)->toBe(['string', 'null'])
        ->and($target->oneOf)->toBe(Generator::UNDEFINED);
});

// endregion

// region multipleOf wiring (#83)

it('writes a non-null multipleOf onto the target schema', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'integer';
    $descriptor->multipleOf = 5;

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->multipleOf)->toBe(5);
});

it('leaves multipleOf undefined when the descriptor does not set it', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'integer';

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect(isUndefined($target->multipleOf))->toBeTrue();
});

// endregion
