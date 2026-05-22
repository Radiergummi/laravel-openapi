<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Attributes\RequestField;

uses()->group('openapi');

it('is a FieldAttribute', function (): void {
    expect(new RequestField())->toBeInstanceOf(FieldAttribute::class);
});

it('maps schema parameters onto the descriptor', function (): void {
    $descriptor = new RequestField(
        description: 'Display name.',
        example: 'Acme Corp',
        type: 'string',
        maxLength: 250,
        writeOnly: true,
    )->descriptor();

    expect($descriptor->description)->toBe('Display name.')
        ->and($descriptor->example)->toBe('Acme Corp')
        ->and($descriptor->type)->toBe('string')
        ->and($descriptor->maxLength)->toBe(250)
        ->and($descriptor->writeOnly)->toBeTrue()
        ->and($descriptor->readOnly)->toBeNull();
});

it('omits null fields from toOpenApi()', function (): void {
    $output = new RequestField(description: 'Only a description.')->descriptor()->toOpenApi();

    expect($output)->toBe(['description' => 'Only a description.']);
});
