<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Attributes;

use Attribute;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use ReflectionClass;

it('exposes its name and forwards schema fields to the descriptor', function (): void {
    $filter = new AllowedFilter('status', type: 'string', description: 'Filter by status.');

    expect($filter->name)->toBe('status')
        ->and($filter->type)->toBe('string')
        ->and($filter->descriptor()->description)->toBe('Filter by status.');
});

it('is repeatable and targets methods', function (): void {
    $attribute = (new ReflectionClass(AllowedFilter::class))
        ->getAttributes(Attribute::class)[0]->newInstance();

    expect($attribute->flags & Attribute::IS_REPEATABLE)->toBe(Attribute::IS_REPEATABLE)
        ->and($attribute->flags & Attribute::TARGET_METHOD)->toBe(Attribute::TARGET_METHOD);
});
