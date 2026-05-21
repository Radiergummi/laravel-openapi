<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources\Attributes;

use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use stdClass;

it('exposes its name and forwards schema fields to the descriptor', function (): void {
    $field = new ResourceField('email', type: 'string', format: 'email');

    expect($field->name)->toBe('email')
        ->and($field->type)->toBe('string')
        ->and($field->descriptor()->format)->toBe('email');
});

it('accepts a class-string as the type for a nested schema', function (): void {
    $field = new ResourceField('owner', type: stdClass::class);

    expect($field->type)->toBe(stdClass::class);
});
