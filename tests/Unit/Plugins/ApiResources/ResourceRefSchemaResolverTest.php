<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\Resolvers\ResourceRefSchemaResolver;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Tests\Support\SchemaFromResourceFactory;
use stdClass;

#[ResourceField('id', type: 'integer')]
class RefFixtureResource extends JsonResource {}

function makeResourceRefResolver(): ResourceRefSchemaResolver
{
    $registry = new ComponentSchemaRegistry();

    return new ResourceRefSchemaResolver(SchemaFromResourceFactory::create($registry, static fn(): array => []));
}

it('resolves a JsonResource subclass to a components ref', function (): void {
    expect(makeResourceRefResolver()->resolveRef(RefFixtureResource::class))
        ->toBe('#/components/schemas/RefFixtureResource');
});

it('returns null for a non-resource class', function (): void {
    expect(makeResourceRefResolver()->resolveRef(stdClass::class))->toBeNull();
});
