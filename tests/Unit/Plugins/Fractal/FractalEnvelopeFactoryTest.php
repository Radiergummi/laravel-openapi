<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use Radiergummi\OpenApi\Plugins\Fractal\FractalEnvelopeFactory;
use Radiergummi\OpenApi\Plugins\Fractal\Serializer;

/** @return list<string> */
function envelopePropertyNames(\OpenApi\Annotations\Schema $schema): array
{
    return array_map(static fn($p) => $p->property, $schema->properties);
}

it('builds a DataArray single-item envelope with a data ref', function (): void {
    $schema = (new FractalEnvelopeFactory())->single('#/components/schemas/Book');

    expect($schema->type)->toBe('object')
        ->and(envelopePropertyNames($schema))->toBe(['data'])
        ->and($schema->properties[0]->ref)->toBe('#/components/schemas/Book');
});

it('builds a DataArray collection envelope with a data array', function (): void {
    $schema = (new FractalEnvelopeFactory())->collection('#/components/schemas/Book');

    expect(envelopePropertyNames($schema))->toBe(['data'])
        ->and($schema->properties[0]->type)->toBe('array');
});

it('builds a DataArray paginated envelope with pagination meta', function (): void {
    $schema = (new FractalEnvelopeFactory())->paginated('#/components/schemas/Book');
    $names = envelopePropertyNames($schema);

    expect($names)->toBe(['data', 'meta'])
        ->and($schema->properties[0]->type)->toBe('array');

    $meta = $schema->properties[1];
    $metaNames = array_map(static fn($p) => $p->property, $meta->properties);
    expect($metaNames)->toBe(['pagination']);

    $paginationKeys = array_map(static fn($p) => $p->property, $meta->properties[0]->properties);
    expect($paginationKeys)->toContain('total', 'count', 'per_page', 'current_page', 'total_pages');
});

it('builds an ArraySerializer single response as a bare $ref', function (): void {
    $schema = (new FractalEnvelopeFactory())->single('#/components/schemas/Book', Serializer::ArraySerializer);

    expect($schema->ref)->toBe('#/components/schemas/Book');
});

it('builds an ArraySerializer collection as a top-level array', function (): void {
    $schema = (new FractalEnvelopeFactory())->collection('#/components/schemas/Book', Serializer::ArraySerializer);

    expect($schema->type)->toBe('array')
        ->and($schema->items)->not->toBeNull()
        ->and($schema->items->ref)->toBe('#/components/schemas/Book');
});

it('keeps the data-array paginated envelope for ArraySerializer (paginator wraps regardless)', function (): void {
    $schema = (new FractalEnvelopeFactory())->paginated('#/components/schemas/Book', Serializer::ArraySerializer);

    expect(envelopePropertyNames($schema))->toBe(['data', 'meta']);
});

it('builds a JsonApi single resource object', function (): void {
    $schema = (new FractalEnvelopeFactory())->single('#/components/schemas/Book', Serializer::JsonApi);

    expect(envelopePropertyNames($schema))->toBe(['data']);
    $data = $schema->properties[0];
    $dataKeys = array_map(static fn($p) => $p->property, $data->properties);

    expect($dataKeys)->toBe(['type', 'id', 'attributes'])
        ->and($data->properties[2]->ref)->toBe('#/components/schemas/Book');
});

it('builds a JsonApi collection of resource objects', function (): void {
    $schema = (new FractalEnvelopeFactory())->collection('#/components/schemas/Book', Serializer::JsonApi);

    expect($schema->properties[0]->type)->toBe('array');
    $itemKeys = array_map(static fn($p) => $p->property, $schema->properties[0]->items->properties);
    expect($itemKeys)->toBe(['type', 'id', 'attributes']);
});

it('builds a JsonApi paginated envelope with hyphenated pagination keys', function (): void {
    $schema = (new FractalEnvelopeFactory())->paginated('#/components/schemas/Book', Serializer::JsonApi);

    expect(envelopePropertyNames($schema))->toBe(['data', 'meta']);
    $paginationKeys = array_map(
        static fn($p) => $p->property,
        $schema->properties[1]->properties[0]->properties,
    );
    expect($paginationKeys)->toContain('total', 'count', 'per-page', 'current-page', 'total-pages');
});
