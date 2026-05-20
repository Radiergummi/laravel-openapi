<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Attributes;

use Attribute;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use ReflectionClass;

it('exposes its name and forwards schema fields to the descriptor', function (): void {
    $field = new TransformerField('title', type: 'string', maxLength: 120);

    expect($field->name)->toBe('title')
        ->and($field->type)->toBe('string')
        ->and($field->descriptor()->maxLength)->toBe(120);
});

it('is repeatable and targets classes', function (): void {
    $attribute = (new ReflectionClass(TransformerField::class))
        ->getAttributes(Attribute::class)[0]->newInstance();

    expect($attribute->flags & Attribute::IS_REPEATABLE)->toBe(Attribute::IS_REPEATABLE)
        ->and($attribute->flags & Attribute::TARGET_CLASS)->toBe(Attribute::TARGET_CLASS);
});
