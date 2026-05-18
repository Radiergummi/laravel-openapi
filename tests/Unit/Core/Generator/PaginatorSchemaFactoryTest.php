<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Generator;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Enums\PaginatorKind;
use Radiergummi\OpenApi\Core\Generator\PaginatorSchemaFactory;

/**
 * @return list<string>
 */
function propertyNames(OA\Schema $schema): array
{
    $names = [];

    foreach ($schema->properties as $property) {
        $names[] = $property->property;
    }

    return $names;
}

it('builds a length-aware envelope with the full toArray() key set', function (): void {
    $items = new OA\Items(['ref' => '#/components/schemas/User']);

    $schema = (new PaginatorSchemaFactory())->envelope(PaginatorKind::LengthAware, $items);
    $names = propertyNames($schema);

    expect($schema->type)->toBe('object')
        ->and($names)->toContain('current_page')
        ->and($names)->toContain('data')
        ->and($names)->toContain('last_page')
        ->and($names)->toContain('total')
        ->and($names)->toContain('per_page')
        ->and($names)->toContain('links');
});

it('omits last_page and total for a simple paginator', function (): void {
    $items = new OA\Items([]);

    $schema = (new PaginatorSchemaFactory())->envelope(PaginatorKind::Simple, $items);
    $names = propertyNames($schema);

    expect($names)->toContain('data')
        ->and($names)->toContain('current_page')
        ->and($names)->not->toContain('last_page')
        ->and($names)->not->toContain('total')
        ->and($names)->not->toContain('last_page_url')
        ->and($names)->not->toContain('links');
});

it('builds a cursor envelope with next_cursor and prev_cursor', function (): void {
    $items = new OA\Items([]);

    $schema = (new PaginatorSchemaFactory())->envelope(PaginatorKind::Cursor, $items);
    $names = propertyNames($schema);

    expect($names)->toContain('data')
        ->and($names)->toContain('next_cursor')
        ->and($names)->toContain('prev_cursor')
        ->and($names)->not->toContain('total')
        ->and($names)->not->toContain('current_page')
        ->and($names)->not->toContain('from')
        ->and($names)->not->toContain('to');
});

it('wires the supplied items into the data array', function (): void {
    $items = new OA\Items(['ref' => '#/components/schemas/User']);

    $schema = (new PaginatorSchemaFactory())->envelope(PaginatorKind::LengthAware, $items);

    $data = null;

    foreach ($schema->properties as $prop) {
        if ($prop->property === 'data') {
            $data = $prop;
        }
    }

    expect($data)->not->toBeNull()
        ->and($data->type)->toBe('array')
        ->and($data->items)->toBe($items);
});
