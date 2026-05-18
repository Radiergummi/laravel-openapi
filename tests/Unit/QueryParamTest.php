<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\QueryParam;
use Radiergummi\OpenApi\Core\Generator\SchemaDescriptor;

uses()->group('openapi');

it('stores name, required, and deprecated as top-level fields', function (): void {
    $attr = new QueryParam('q', required: true, deprecated: true);

    expect($attr->name)->toBe('q')
        ->and($attr->required)->toBeTrue()
        ->and($attr->deprecated)->toBeTrue();
});

it('defaults required and deprecated to false', function (): void {
    $attr = new QueryParam('q');

    expect($attr->required)->toBeFalse()
        ->and($attr->deprecated)->toBeFalse();
});

it('descriptor() returns a SchemaDescriptor with schema fields', function (): void {
    $attr = new QueryParam(
        'limit',
        type: 'integer',
        description: 'Max results to return.',
        minimum: 1,
        maximum: 100,
        default: 25,
    );

    $descriptor = $attr->descriptor();

    expect($descriptor)->toBeInstanceOf(SchemaDescriptor::class)
        ->and($descriptor->type)->toBe('integer')
        ->and($descriptor->description)->toBe('Max results to return.')
        ->and($descriptor->minimum)->toBe(1)
        ->and($descriptor->maximum)->toBe(100)
        ->and($descriptor->default)->toBe(25);
});

it('descriptor() does not expose required or deprecated (those are parameter-level, not schema-level)', function (): void {
    $attr = new QueryParam('q', required: true, deprecated: true);

    $output = $attr->descriptor()->toOpenApi();

    expect($output)->not->toHaveKey('required')
        ->and($output)->not->toHaveKey('deprecated');
});

it('descriptor()->toOpenApi() omits null schema fields', function (): void {
    $attr = new QueryParam('q', type: 'string');

    $output = $attr->descriptor()->toOpenApi();

    expect($output)->toBe(['type' => 'string'])
        ->and($output)->not->toHaveKey('format')
        ->and($output)->not->toHaveKey('description');
});

it('descriptor()->toOpenApi() includes all set schema fields', function (): void {
    $attr = new QueryParam(
        'search',
        description: 'Free-text search.',
        example: 'cnc machining',
        format: 'uri',
        minLength: 3,
        maxLength: 100,
        pattern: '^[a-z]+$',
    );

    $output = $attr->descriptor()->toOpenApi();

    expect($output['description'])->toBe('Free-text search.')
        ->and($output['example'])->toBe('cnc machining')
        ->and($output['format'])->toBe('uri')
        ->and($output['minLength'])->toBe(3)
        ->and($output['maxLength'])->toBe(100)
        ->and($output['pattern'])->toBe('^[a-z]+$');
});
