<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\QueryParam;
use Radiergummi\OpenApi\Core\Attributes\RequestField;

uses()->group('attributes', 'openapi');

it('strips directives from the description in RequestField::descriptor()', function (): void {
    $field = new RequestField(
        description: "The product price.\nExample: 1999",
    );

    $descriptor = $field->descriptor();

    expect($descriptor->description)->toBe('The product price.')
        ->and($descriptor->example)->toBe(1999);
});

it('honours an explicit example over an inline Example: directive', function (): void {
    $field = new RequestField(
        description: "Price.\nExample: 1999",
        example: 2999,
    );

    $descriptor = $field->descriptor();

    expect($descriptor->example)->toBe(2999);
});

it('honours No-example by leaving example null', function (): void {
    $field = new QueryParam(
        name: 'limit',
        description: "Limit.\nNo-example",
    );

    $descriptor = $field->descriptor();

    expect($descriptor->example)->toBeNull();
});

it('No-example overrides an explicit example argument', function (): void {
    $field = new QueryParam(
        name: 'limit',
        description: "Limit.\nNo-example",
        example: 9999,
    );

    expect($field->descriptor()->example)->toBeNull();
});

it('applies Enum: directive when no explicit enum is set', function (): void {
    $field = new QueryParam(
        name: 'status',
        description: "Status filter.\nEnum: pending, active, archived",
    );

    $descriptor = $field->descriptor();

    expect($descriptor->enum)->toBe(['pending', 'active', 'archived']);
});

it('preserves an explicit enum over an Enum: directive', function (): void {
    $field = new QueryParam(
        name: 'status',
        description: "Status.\nEnum: foo, bar",
        enum: ['pending', 'active'],
    );

    $descriptor = $field->descriptor();

    expect($descriptor->enum)->toBe(['pending', 'active']);
});
