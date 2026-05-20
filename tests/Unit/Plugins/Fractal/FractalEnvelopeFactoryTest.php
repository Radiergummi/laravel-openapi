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

/** @return list<string> */
function envelopePropertyNames(\OpenApi\Annotations\Schema $schema): array
{
    return array_map(static fn($p) => $p->property, $schema->properties);
}

it('builds a single-item envelope with a data ref', function (): void {
    $schema = (new FractalEnvelopeFactory())->single('#/components/schemas/Book');

    expect($schema->type)->toBe('object')
        ->and(envelopePropertyNames($schema))->toBe(['data'])
        ->and($schema->properties[0]->ref)->toBe('#/components/schemas/Book');
});

it('builds a collection envelope with a data array', function (): void {
    $schema = (new FractalEnvelopeFactory())->collection('#/components/schemas/Book');

    expect(envelopePropertyNames($schema))->toBe(['data'])
        ->and($schema->properties[0]->type)->toBe('array');
});

it('builds a paginated envelope with pagination meta', function (): void {
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
