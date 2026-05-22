<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Attributes\ResponseField;

uses()->group('openapi');

it('is a FieldAttribute', function (): void {
    expect(new ResponseField())->toBeInstanceOf(FieldAttribute::class);
});

it('maps schema parameters onto the descriptor', function (): void {
    $descriptor = new ResponseField(
        type: 'integer',
        description: 'Number of items in the bucket.',
        example: 7,
        readOnly: true,
    )->descriptor();

    expect($descriptor->type)->toBe('integer')
        ->and($descriptor->description)->toBe('Number of items in the bucket.')
        ->and($descriptor->example)->toBe(7)
        ->and($descriptor->readOnly)->toBeTrue()
        ->and($descriptor->writeOnly)->toBeNull();
});
