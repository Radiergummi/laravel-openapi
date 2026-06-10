<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Tests\Support\SchemaFromResourceFactory;

use function array_find;
use function Radiergummi\OpenApi\is_undefined;

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

#[ResourceField('members', type: 'array', items: SchemaOwnerResource::class)]
#[ResourceField('labels', type: 'array', items: 'string')]
class SchemaTeamResource extends JsonResource {}

#[ResourceField('tags', type: 'array', items: SchemaNonResourceModel::class)]
class SchemaArrayExternalRefResource extends JsonResource {}

it('builds an object schema from #[ResourceField] attributes', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = SchemaFromResourceFactory::create($registry, static fn(): array => [])->build(SchemaProjectResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema)->toBeInstanceOf(OA\Schema::class)
        ->and($schema->type)->toBe('object');

    $names = array_map(static fn(OA\Property $p): string => $p->property, $schema->properties);
    expect($names)->toContain('id')->toContain('name')->toContain('owner')->toContain('avatar');
});

it('omits conditional fields from required', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = SchemaFromResourceFactory::create($registry, static fn(): array => [])->build(SchemaProjectResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema->required)->toContain('id')
        ->and($schema->required)->not->toContain('avatar');
});

it('emits a $ref for a nested resource and registers it', function (): void {
    $registry = new ComponentSchemaRegistry();
    SchemaFromResourceFactory::create($registry, static fn(): array => [])->build(SchemaProjectResource::class);

    $keys = array_map(static fn(OA\Schema $s): string => $s->schema, $registry->all());
    expect($keys)->toContain('SchemaOwnerResource');
});

it('resolves a non-resource field type via an injected RefSchemaResolver', function (): void {
    $registry = new ComponentSchemaRegistry();

    $stub = new class () implements RefSchemaResolver {
        public function canResolve(string $class): bool
        {
            return $class === SchemaNonResourceModel::class;
        }

        public function resolveRef(string $class): ?string
        {
            return $this->canResolve($class)
                ? '#/components/schemas/SchemaNonResourceModel'
                : null;
        }
    };

    $key = SchemaFromResourceFactory::create($registry, static fn(): array => [$stub])->build(SchemaWithExternalRefResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema)->toBeInstanceOf(OA\Schema::class);

    $tagProperty = array_find($schema->properties, static fn(OA\Property $p): bool => $p->property === 'tag');

    expect($tagProperty)->toBeInstanceOf(OA\Property::class)
        ->and($tagProperty->ref)->toBe('#/components/schemas/SchemaNonResourceModel');
});

it('emits an array of $ref for a nested resource collection field', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = SchemaFromResourceFactory::create($registry, static fn(): array => [])->build(SchemaTeamResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    $members = array_find($schema->properties, static fn(OA\Property $p): bool => $p->property === 'members');

    expect($members->type)->toBe('array')
        ->and($members->items)->toBeInstanceOf(OA\Items::class)
        ->and($members->items->ref)->toBe('#/components/schemas/SchemaOwnerResource');

    $keys = array_map(static fn(OA\Schema $s): string => $s->schema, $registry->all());
    expect($keys)->toContain('SchemaOwnerResource');
});

it('resolves an array items class via an injected RefSchemaResolver', function (): void {
    $registry = new ComponentSchemaRegistry();

    $stub = new class () implements RefSchemaResolver {
        public function canResolve(string $class): bool
        {
            return $class === SchemaNonResourceModel::class;
        }

        public function resolveRef(string $class): ?string
        {
            return $this->canResolve($class)
                ? '#/components/schemas/SchemaNonResourceModel'
                : null;
        }
    };

    $key = SchemaFromResourceFactory::create($registry, static fn(): array => [$stub])->build(SchemaArrayExternalRefResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    $tags = array_find($schema->properties, static fn(OA\Property $p): bool => $p->property === 'tags');

    expect($tags->type)->toBe('array')
        ->and($tags->items)->toBeInstanceOf(OA\Items::class)
        ->and($tags->items->ref)->toBe('#/components/schemas/SchemaNonResourceModel');
});

it('degrades an unresolvable array items class to a permissive object item', function (): void {
    $registry = new ComponentSchemaRegistry();

    // No resolvers, and the items class is not a JsonResource, so the class-string
    // resolves to no $ref and the items schema degrades to a permissive object.
    $key = SchemaFromResourceFactory::create($registry, static fn(): array => [])->build(SchemaArrayExternalRefResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    $tags = array_find($schema->properties, static fn(OA\Property $p): bool => $p->property === 'tags');

    expect($tags->type)->toBe('array')
        ->and($tags->items)->toBeInstanceOf(OA\Items::class)
        ->and($tags->items->type)->toBe('object')
        ->and(is_undefined($tags->items->ref))->toBeTrue();
});

it('still emits a scalar items type for a non-class array items', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = SchemaFromResourceFactory::create($registry, static fn(): array => [])->build(SchemaTeamResource::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    $labels = array_find($schema->properties, static fn(OA\Property $p): bool => $p->property === 'labels');

    expect($labels->type)->toBe('array')
        ->and($labels->items)->toBeInstanceOf(OA\Items::class)
        ->and($labels->items->type)->toBe('string')
        ->and(is_undefined($labels->items->ref))->toBeTrue();
});
