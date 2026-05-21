<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\ResponseHeader;

uses()->group('openapi');

it('captures all constructor arguments', function (): void {
    $header = new ResponseHeader(
        name: 'Location',
        status: 201,
        description: 'URL of the created resource',
        type: 'string',
        format: 'uri',
        example: '/api/flights/123',
        required: true,
        deprecated: false,
    );

    expect($header->name)->toBe('Location')
        ->and($header->status)->toBe(201)
        ->and($header->description)->toBe('URL of the created resource')
        ->and($header->type)->toBe('string')
        ->and($header->format)->toBe('uri')
        ->and($header->example)->toBe('/api/flights/123')
        ->and($header->required)->toBeTrue()
        ->and($header->deprecated)->toBeFalse();
});

it('defaults status to 200 and type to string', function (): void {
    $header = new ResponseHeader(name: 'X-Request-Id');

    expect($header->status)->toBe(200)
        ->and($header->type)->toBe('string')
        ->and($header->description)->toBeNull()
        ->and($header->format)->toBeNull()
        ->and($header->required)->toBeNull();
});
