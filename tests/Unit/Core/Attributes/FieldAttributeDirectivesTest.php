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
        description: "The product price.\n@example 1999",
    );

    $descriptor = $field->descriptor();

    expect($descriptor->description)->toBe('The product price.')
        ->and($descriptor->example)->toBe(1999);
});

it('honours an explicit example over an inline @example directive', function (): void {
    $field = new RequestField(
        description: "Price.\n@example 1999",
        example: 2999,
    );

    $descriptor = $field->descriptor();

    expect($descriptor->example)->toBe(2999);
});

it('honours @no-example by leaving example null when no explicit arg given', function (): void {
    $field = new QueryParam(
        name: 'limit',
        description: "Limit.\n@no-example",
    );

    $descriptor = $field->descriptor();

    expect($descriptor->example)->toBeNull();
});

it('honours an explicit example: null to suppress the directive-derived example', function (): void {
    $field = new RequestField(
        description: "Phone.\n@example 555-1234",
        example: null,
    );

    expect($field->descriptor()->example)->toBeNull();
});

it('lets an explicit example argument win over @no-example', function (): void {
    $field = new QueryParam(
        name: 'limit',
        description: "Limit.\n@no-example",
        example: 9999,
    );

    expect($field->descriptor()->example)->toBe(9999);
});

it('applies @enum directive when no explicit enum is set', function (): void {
    $field = new QueryParam(
        name: 'status',
        description: "Status filter.\n@enum pending, active, archived",
    );

    $descriptor = $field->descriptor();

    expect($descriptor->enum)->toBe(['pending', 'active', 'archived']);
});

it('preserves an explicit enum over an @enum directive', function (): void {
    $field = new QueryParam(
        name: 'status',
        description: "Status.\n@enum foo, bar",
        enum: ['pending', 'active'],
    );

    $descriptor = $field->descriptor();

    expect($descriptor->enum)->toBe(['pending', 'active']);
});

it('honours an explicit enum: null to suppress the directive-derived enum', function (): void {
    $field = new QueryParam(
        name: 'status',
        description: "Status.\n@enum foo, bar",
        enum: null,
    );

    expect($field->descriptor()->enum)->toBeNull();
});

it('coerces integer @enum tokens to ints in the descriptor', function (): void {
    $field = new RequestField(
        description: "Status code.\n@enum 200, 404, 500",
        type: 'integer',
    );

    expect($field->descriptor()->enum)->toBe([200, 404, 500]);
});
