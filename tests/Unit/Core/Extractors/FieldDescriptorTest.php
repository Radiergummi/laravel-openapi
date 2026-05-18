<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Core\Extractors\FieldDescriptor;

function isUndefined(mixed $value): bool
{
    return Generator::isDefault($value);
}

uses()->group('openapi');

// ---------------------------------------------------------------------------
// Bug 6: nullable object — properties/additionalProperties/required migration
// ---------------------------------------------------------------------------

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

    $inner = $target->oneOf[0];
    $null = $target->oneOf[1];

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

    $inner = $target->oneOf[0];

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

    $inner = $target->oneOf[0];

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

    $inner = $target->oneOf[0];

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
