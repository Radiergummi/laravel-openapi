<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Support\Generator\SchemaDescriptor;

uses()->group('openapi');

it('descriptor() returns a SchemaDescriptor with schema fields', function (): void {
    $attribute = new QueryParam(
        'limit',
        description: 'Max results to return.',
        type: 'integer',
        default: 25,
        minimum: 1,
        maximum: 100,
    );

    $descriptor = $attribute->descriptor();

    expect($descriptor)->toBeInstanceOf(SchemaDescriptor::class)
        ->and($descriptor->type)->toBe('integer')
        ->and($descriptor->description)->toBe('Max results to return.')
        ->and($descriptor->minimum)->toBe(1)
        ->and($descriptor->maximum)->toBe(100)
        ->and($descriptor->default)->toBe(25);
});

it('descriptor() does not expose required or deprecated (those are parameter-level, not schema-level)', function (): void {
    $attribute = new QueryParam('q', required: true, deprecated: true);

    $output = $attribute->descriptor()->toOpenApi();

    expect($output)->not->toHaveKey('required')
        ->and($output)->not->toHaveKey('deprecated');
});

it('descriptor()->toOpenApi() omits null schema fields', function (): void {
    $attribute = new QueryParam('q', type: 'string');

    $output = $attribute->descriptor()->toOpenApi();

    expect($output)->toBe(['type' => 'string'])
        ->and($output)->not->toHaveKey('format')
        ->and($output)->not->toHaveKey('description');
});

it('descriptor()->toOpenApi() includes all set schema fields', function (): void {
    $attribute = new QueryParam(
        'search',
        description: 'Free-text search.',
        example: 'cnc machining',
        format: 'uri',
        minLength: 3,
        maxLength: 100,
        pattern: '^[a-z]+$',
    );

    $output = $attribute->descriptor()->toOpenApi();

    expect($output['description'])->toBe('Free-text search.')
        ->and($output['example'])->toBe('cnc machining')
        ->and($output['format'])->toBe('uri')
        ->and($output['minLength'])->toBe(3)
        ->and($output['maxLength'])->toBe(100)
        ->and($output['pattern'])->toBe('^[a-z]+$');
});
