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
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\SchemaFromResource;

use function array_find;

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string')]
#[ResourceField('owner', type: SchemaOwnerResource::class)]
#[ResourceField('avatar', type: 'string', conditional: true)]
class SchemaProjectResource extends JsonResource {}

#[ResourceField('id', type: 'integer')]
class SchemaOwnerResource extends JsonResource {}

#[ResourceField('tag', type: SchemaNonResourceModel::class)]
class SchemaWithExternalRefResource extends JsonResource {}

/** Not a JsonResource — resolved via RefSchemaResolver */
class SchemaNonResourceModel {}

it('builds an object schema from #[ResourceField] attributes', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = (new SchemaFromResource($registry, static fn(): array => []))->build(SchemaProjectResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema)->toBeInstanceOf(OA\Schema::class)
        ->and($schema->type)->toBe('object');

    $names = array_map(static fn(OA\Property $p): string => $p->property, $schema->properties);
    expect($names)->toContain('id')->toContain('name')->toContain('owner')->toContain('avatar');
});

it('omits conditional fields from required', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = (new SchemaFromResource($registry, static fn(): array => []))->build(SchemaProjectResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema->required)->toContain('id')
        ->and($schema->required)->not->toContain('avatar');
});

it('emits a $ref for a nested resource and registers it', function (): void {
    $registry = new ComponentSchemaRegistry();
    (new SchemaFromResource($registry, static fn(): array => []))->build(SchemaProjectResource::class);

    $keys = array_map(static fn(OA\Schema $s): string => $s->schema, $registry->all());
    expect($keys)->toContain('SchemaOwnerResource');
});

it('resolves a non-resource field type via an injected RefSchemaResolver', function (): void {
    $registry = new ComponentSchemaRegistry();

    $stub = new class () implements RefSchemaResolver {
        public function resolveRef(string $class): ?string
        {
            return $class === SchemaNonResourceModel::class
                ? '#/components/schemas/SchemaNonResourceModel'
                : null;
        }
    };

    $key = (new SchemaFromResource($registry, static fn(): array => [$stub]))->build(SchemaWithExternalRefResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema)->toBeInstanceOf(OA\Schema::class);

    $tagProperty = array_find($schema->properties, static fn(OA\Property $p): bool => $p->property === 'tag');

    expect($tagProperty)->toBeInstanceOf(OA\Property::class)
        ->and($tagProperty->ref)->toBe('#/components/schemas/SchemaNonResourceModel');
});
