<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceEnvelopeFactory;

/** @return array<string, OA\Property> */
function envelopeProperties(OA\Schema $schema): array
{
    $byName = [];

    foreach ($schema->properties as $property) {
        $byName[$property->property] = $property;
    }

    return $byName;
}

it('wraps a single resource in a data object', function (): void {
    $schema = new ResourceEnvelopeFactory()->single('#/components/schemas/Project');
    $properties = envelopeProperties($schema);

    expect($schema->type)->toBe('object')
        ->and($properties)->toHaveKeys(['data'])
        ->and($properties['data']->ref)->toBe('#/components/schemas/Project');
});

it('wraps a collection in data/links/meta', function (): void {
    $schema = new ResourceEnvelopeFactory()->collection('#/components/schemas/Project');
    $properties = envelopeProperties($schema);

    expect($properties)->toHaveKeys(['data', 'links', 'meta'])
        ->and($properties['data']->type)->toBe('array')
        ->and($properties['data']->items->ref)->toBe('#/components/schemas/Project');
});
