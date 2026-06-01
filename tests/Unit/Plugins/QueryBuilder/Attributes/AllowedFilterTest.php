<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Attributes;

use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;

it('exposes its name and forwards schema fields to the descriptor', function (): void {
    $filter = new AllowedFilter('status', description: 'Filter by status.', type: 'string');

    expect($filter->name)->toBe('status')
        ->and($filter->type)->toBe('string')
        ->and($filter->descriptor()->description)->toBe('Filter by status.');
});

it('forwards the full FieldAttribute surface QueryParam exposes', function (): void {
    $filter = new AllowedFilter(
        'amount',
        type: 'integer',
        nullable: true,
        default: 0,
        minimum: 1,
        maximum: 99,
        exclusiveMinimum: 0,
        exclusiveMaximum: 100,
        multipleOf: 5,
        minLength: 1,
        maxLength: 3,
        pattern: '^\d+$',
        minItems: 1,
        maxItems: 10,
        uniqueItems: true,
    );

    $descriptor = $filter->descriptor();

    expect($descriptor->nullable)->toBeTrue()
        ->and($descriptor->default)->toBe(0)
        ->and($descriptor->minimum)->toBe(1)
        ->and($descriptor->maximum)->toBe(99)
        ->and($descriptor->exclusiveMinimum)->toBe(0)
        ->and($descriptor->exclusiveMaximum)->toBe(100)
        ->and($descriptor->multipleOf)->toBe(5)
        ->and($descriptor->minLength)->toBe(1)
        ->and($descriptor->maxLength)->toBe(3)
        ->and($descriptor->pattern)->toBe('^\d+$')
        ->and($descriptor->minItems)->toBe(1)
        ->and($descriptor->maxItems)->toBe(10)
        ->and($descriptor->uniqueItems)->toBeTrue();
});
