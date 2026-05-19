<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceRefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\SchemaFromResource;
use stdClass;

#[ResourceField('id', type: 'integer')]
class RefFixtureResource extends JsonResource {}

function makeResourceRefResolver(): ResourceRefSchemaResolver
{
    $registry = new ComponentSchemaRegistry();

    return new ResourceRefSchemaResolver(new SchemaFromResource($registry, []));
}

it('resolves a JsonResource subclass to a components ref', function (): void {
    expect(makeResourceRefResolver()->resolveRef(RefFixtureResource::class))
        ->toBe('#/components/schemas/RefFixtureResource');
});

it('returns null for a non-resource class', function (): void {
    expect(makeResourceRefResolver()->resolveRef(stdClass::class))->toBeNull();
});
