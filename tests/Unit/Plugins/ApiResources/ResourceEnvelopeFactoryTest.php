<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceEnvelopeFactory;

/** @return list<string> */
function envelopePropertyNames(OA\Schema $schema): array
{
    $names = [];

    foreach ($schema->properties as $property) {
        $names[] = $property->property;
    }

    return $names;
}

it('wraps a single resource in a data object', function (): void {
    $schema = (new ResourceEnvelopeFactory())->single('#/components/schemas/Project');

    expect($schema->type)->toBe('object')
        ->and(envelopePropertyNames($schema))->toBe(['data']);

    $data = $schema->properties[0];
    expect($data->ref)->toBe('#/components/schemas/Project');
});

it('wraps a collection in data/links/meta', function (): void {
    $schema = (new ResourceEnvelopeFactory())->collection('#/components/schemas/Project');
    $names = envelopePropertyNames($schema);

    expect($names)->toContain('data')
        ->and($names)->toContain('links')
        ->and($names)->toContain('meta');

    $data = $schema->properties[0];
    expect($data->type)->toBe('array')
        ->and($data->items->ref)->toBe('#/components/schemas/Project');
});
