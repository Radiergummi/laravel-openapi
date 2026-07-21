<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Tests\Support\SchemaFromResourceFactory;

use function array_find;
use function class_exists;

// Laravel 13 introduced first-party JSON:API resources; on 12 there is nothing to document.
if (!class_exists(JsonApiResource::class)) {
    return;
}

require_once __DIR__ . '/Fixtures/JsonApiResourceFixtures.php';

/**
 * @param class-string<\Illuminate\Http\Resources\Json\JsonResource> $resourceClass
 */
function jsonApiSchema(string $resourceClass): OA\Schema
{
    $registry = new ComponentSchemaRegistry();
    $key = SchemaFromResourceFactory::create($registry)->build($resourceClass);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    assert($schema instanceof OA\Schema);

    return $schema;
}

/**
 * @param list<OA\Property> $properties
 */
function property(array $properties, string $name): ?OA\Property
{
    return array_find($properties, static fn(OA\Property $p): bool => $p->property === $name);
}

it('builds a JSON:API resource object rather than a flat field bag', function (): void {
    $schema = jsonApiSchema(Fixtures\JsonApiArticleResource::class);

    expect($schema->type)->toBe('object')
        ->and($schema->required)->toBe(['type', 'id']);

    $names = array_map(static fn(OA\Property $p): string => $p->property, $schema->properties);

    expect($names)->toContain('type')
        ->toContain('id')
        ->toContain('attributes')
        ->toContain('relationships');
});

it('reads toAttributes() keys, including self:: constant keys', function (): void {
    $attributes = property(jsonApiSchema(Fixtures\JsonApiArticleResource::class)->properties, 'attributes');

    $names = array_map(static fn(OA\Property $p): string => $p->property, $attributes->properties);

    expect($attributes->type)->toBe('object')
        ->and($names)->toContain('title')
        ->toContain('body');
});

it('documents relationships as objects, never as the related value type', function (): void {
    $relationships = property(jsonApiSchema(Fixtures\JsonApiArticleResource::class)->properties, 'relationships');

    $author = property($relationships->properties, 'author');

    expect($author)->not->toBeNull()
        ->and($author->type)->toBe('object');
});

it('omits members the resource does not override', function (): void {
    $names = array_map(
        static fn(OA\Property $p): string => $p->property,
        jsonApiSchema(Fixtures\JsonApiMinimalResource::class)->properties,
    );

    expect($names)->toContain('attributes')
        ->not->toContain('relationships')
        ->not->toContain('links')
        ->not->toContain('meta');
});

it('still emits type and id when the attribute body is not statically readable', function (): void {
    $schema = jsonApiSchema(Fixtures\JsonApiDynamicResource::class);

    $names = array_map(static fn(OA\Property $p): string => $p->property, $schema->properties);

    expect($names)->toBe(['type', 'id']);
});
