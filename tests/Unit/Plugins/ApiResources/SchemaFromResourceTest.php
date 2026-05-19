<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\SchemaFromResource;

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string')]
#[ResourceField('owner', type: SchemaOwnerResource::class)]
#[ResourceField('avatar', type: 'string', conditional: true)]
class SchemaProjectResource extends JsonResource {}

#[ResourceField('id', type: 'integer')]
class SchemaOwnerResource extends JsonResource {}

it('builds an object schema from #[ResourceField] attributes', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = (new SchemaFromResource($registry, []))->build(SchemaProjectResource::class);

    $schema = null;

    foreach ($registry->all() as $candidate) {
        if ($candidate->schema === $key) {
            $schema = $candidate;
        }
    }

    expect($schema)->toBeInstanceOf(OA\Schema::class)
        ->and($schema->type)->toBe('object');

    $names = array_map(static fn(OA\Property $p): string => $p->property, $schema->properties);
    expect($names)->toContain('id')->toContain('name')->toContain('owner')->toContain('avatar');
});

it('omits conditional fields from required', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = (new SchemaFromResource($registry, []))->build(SchemaProjectResource::class);

    $schema = null;

    foreach ($registry->all() as $candidate) {
        if ($candidate->schema === $key) {
            $schema = $candidate;
        }
    }

    expect($schema->required)->toContain('id')
        ->and($schema->required)->not->toContain('avatar');
});

it('emits a $ref for a nested resource and registers it', function (): void {
    $registry = new ComponentSchemaRegistry();
    (new SchemaFromResource($registry, []))->build(SchemaProjectResource::class);

    $keys = array_map(static fn(OA\Schema $s): string => $s->schema, $registry->all());
    expect($keys)->toContain('SchemaOwnerResource');
});
