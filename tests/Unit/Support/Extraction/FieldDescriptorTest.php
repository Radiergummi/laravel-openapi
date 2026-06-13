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

// region nullable array/object — constraint migration into the oneOf inner branch

it('migrates minItems and maxItems into the inner branch of a nullable array', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'array';
    $descriptor->nullable = true;
    $descriptor->minItems = 1;
    $descriptor->maxItems = 10;

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);

    expect($inner->type)->toBe('array')
        ->and($inner->minItems)->toBe(1)
        ->and($inner->maxItems)->toBe(10)
        ->and(isUndefined($target->minItems))->toBeTrue()
        ->and(isUndefined($target->maxItems))->toBeTrue();
});

it('migrates minimum and maximum into the inner branch of a nullable object', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'object';
    $descriptor->nullable = true;
    $descriptor->minimum = 0;
    $descriptor->maximum = 100;

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);

    expect($inner->minimum)->toBe(0)
        ->and($inner->maximum)->toBe(100)
        ->and(isUndefined($target->minimum))->toBeTrue()
        ->and(isUndefined($target->maximum))->toBeTrue();
});

it('migrates minLength, maxLength, and pattern into the inner branch of a nullable array', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'array';
    $descriptor->nullable = true;
    $descriptor->minLength = 2;
    $descriptor->maxLength = 8;
    $descriptor->pattern = '^[a-z]+$';

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);

    expect($inner->minLength)->toBe(2)
        ->and($inner->maxLength)->toBe(8)
        ->and($inner->pattern)->toBe('^[a-z]+$')
        ->and(isUndefined($target->minLength))->toBeTrue()
        ->and(isUndefined($target->maxLength))->toBeTrue()
        ->and(isUndefined($target->pattern))->toBeTrue();
});

it('migrates format and enum into the inner branch of a nullable array', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'array';
    $descriptor->nullable = true;
    $descriptor->format = 'date';
    $descriptor->enum = ['a', 'b'];

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);

    expect($inner->format)->toBe('date')
        ->and($inner->enum)->toBe(['a', 'b'])
        ->and(isUndefined($target->format))->toBeTrue()
        ->and(isUndefined($target->enum))->toBeTrue();
});

it('migrates multipleOf into the inner branch of a nullable object', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'object';
    $descriptor->nullable = true;
    $descriptor->multipleOf = 5;

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->oneOf)->toBeArray()->toHaveCount(2);

    $inner = nonNullBranch($target->oneOf);

    expect($inner->multipleOf)->toBe(5)
        ->and(isUndefined($target->multipleOf))->toBeTrue();
});

it('keeps description and example on the outer schema of a nullable array', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'array';
    $descriptor->nullable = true;
    $descriptor->description = 'A list of items';
    $descriptor->example = ['foo'];

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->oneOf)->toBeArray()->toHaveCount(2);

    // Description and example are field-level metadata — they stay on the outer schema.
    expect($target->description)->toBe('A list of items')
        ->and($target->example)->toBe(['foo']);
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

// region $ref to a shared component (#35)

it('applies a $ref, replacing inline schema keywords', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->ref = '#/components/schemas/Status';

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect($target->ref)->toBe('#/components/schemas/Status')
        ->and(isUndefined($target->type))->toBeTrue();
});

it('wraps a nullable $ref in oneOf: [{$ref}, {null}] per OAS 3.1', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->ref = '#/components/schemas/Status';
    $descriptor->nullable = true;

    $target = new OA\Schema([]);
    $descriptor->applyTo($target);

    expect(isUndefined($target->ref))->toBeTrue()
        ->and($target->oneOf[0]->ref)->toBe('#/components/schemas/Status')
        ->and($target->oneOf[1]->type)->toBe('null');
});

// endregion
