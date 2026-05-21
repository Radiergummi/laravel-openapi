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

/** @return array<string, \OpenApi\Annotations\Property> */
function envelopeProperties(\OpenApi\Annotations\Schema $schema): array
{
    $byName = [];

    foreach ($schema->properties as $property) {
        $byName[$property->property] = $property;
    }

    return $byName;
}

it('builds a DataArray single-item envelope with a data ref', function (): void {
    $schema = (new FractalEnvelopeFactory())->single('#/components/schemas/Book');
    $properties = envelopeProperties($schema);

    expect($schema->type)->toBe('object')
        ->and($properties)->toHaveKeys(['data'])
        ->and($properties['data']->ref)->toBe('#/components/schemas/Book');
});

it('builds a DataArray collection envelope with a data array', function (): void {
    $schema = (new FractalEnvelopeFactory())->collection('#/components/schemas/Book');
    $properties = envelopeProperties($schema);

    expect($properties)->toHaveKeys(['data'])
        ->and($properties['data']->type)->toBe('array');
});

it('builds a DataArray paginated envelope with pagination meta', function (): void {
    $schema = (new FractalEnvelopeFactory())->paginated('#/components/schemas/Book');
    $properties = envelopeProperties($schema);

    expect($properties)->toHaveKeys(['data', 'meta'])
        ->and($properties['data']->type)->toBe('array');

    $metaProperties = envelopeProperties($properties['meta']);
    expect($metaProperties)->toHaveKeys(['pagination']);

    $paginationKeys = array_keys(envelopeProperties($metaProperties['pagination']));
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

    expect(envelopeProperties($schema))->toHaveKeys(['data', 'meta']);
});

it('builds a JsonApi single resource object', function (): void {
    $schema = (new FractalEnvelopeFactory())->single('#/components/schemas/Book', Serializer::JsonApi);
    $properties = envelopeProperties($schema);

    expect($properties)->toHaveKeys(['data']);

    $dataProperties = envelopeProperties($properties['data']);

    expect($dataProperties)->toHaveKeys(['type', 'id', 'attributes'])
        ->and($dataProperties['attributes']->ref)->toBe('#/components/schemas/Book');
});

it('builds a JsonApi collection of resource objects', function (): void {
    $schema = (new FractalEnvelopeFactory())->collection('#/components/schemas/Book', Serializer::JsonApi);
    $properties = envelopeProperties($schema);

    expect($properties['data']->type)->toBe('array')
        ->and(array_keys(envelopeProperties($properties['data']->items)))->toContain('type', 'id', 'attributes');
});

it('builds a JsonApi paginated envelope with hyphenated pagination keys', function (): void {
    $schema = (new FractalEnvelopeFactory())->paginated('#/components/schemas/Book', Serializer::JsonApi);
    $properties = envelopeProperties($schema);

    expect($properties)->toHaveKeys(['data', 'meta']);

    $pagination = envelopeProperties($properties['meta'])['pagination'];
    $paginationKeys = array_keys(envelopeProperties($pagination));

    expect($paginationKeys)->toContain('total', 'count', 'per-page', 'current-page', 'total-pages');
});
