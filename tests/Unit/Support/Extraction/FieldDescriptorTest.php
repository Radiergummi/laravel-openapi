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

// region example wiring (#41)

it('writes a non-null example onto the target schema', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';
    $descriptor->example = 'ABC-123';

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->example)->toBe('ABC-123');
});

it('leaves example undefined when the descriptor does not set it', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect(isUndefined($target->example))->toBeTrue();
});

it('does not clobber an existing target example when merging', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->example = 'from-rule';

    $target = new OA\Schema([]);
    $target->example = 'from-type-pass';

    $descriptor->applyTo($target, overwrite: false);

    expect($target->example)->toBe('from-type-pass');
});

// endregion

// region nested properties / items (#28)

it('emits nested object properties with a required list', function (): void {
    $city = new FieldDescriptor();
    $city->type = 'string';
    $city->required = true;

    $descriptor = new FieldDescriptor();
    $descriptor->type = 'object';
    $descriptor->properties = ['city' => $city];

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->type)->toBe('object')
        ->and($target->properties)->toBeArray()->toHaveCount(1)
        ->and($target->properties[0]->property)->toBe('city')
        ->and($target->properties[0]->type)->toBe('string')
        ->and($target->required)->toBe(['city']);
});

it('emits array items from a nested items descriptor', function (): void {
    $element = new FieldDescriptor();
    $element->type = 'string';

    $descriptor = new FieldDescriptor();
    $descriptor->type = 'array';
    $descriptor->items = $element;

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->type)->toBe('array')
        ->and($target->items)->toBeInstanceOf(OA\Items::class)
        ->and($target->items->type)->toBe('string');
});

it('emits a deep array-of-object with a nested object property', function (): void {
    $cityField = new FieldDescriptor();
    $cityField->type = 'string';

    $addressField = new FieldDescriptor();
    $addressField->type = 'object';
    $addressField->properties = ['city' => $cityField];

    $element = new FieldDescriptor();
    $element->type = 'object';
    $element->properties = ['address' => $addressField];

    $descriptor = new FieldDescriptor();
    $descriptor->type = 'array';
    $descriptor->items = $element;

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->type)->toBe('array')
        ->and($target->items->type)->toBe('object')
        ->and($target->items->properties[0]->property)->toBe('address')
        ->and($target->items->properties[0]->properties[0]->property)->toBe('city');
});

// endregion
