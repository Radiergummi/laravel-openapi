<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use Radiergummi\OpenApi\Plugins\Fractal\SchemaFromTransformer;

use function array_find;

#[TransformerField('id', type: 'integer')]
#[TransformerField('title', type: 'string', maxLength: 120)]
#[TransformerInclude('author', transformer: SchemaAuthorTransformer::class, default: true)]
class SchemaBookTransformer {}

#[TransformerField('name', type: 'string')]
class SchemaAuthorTransformer {}

class NotAFractalTransformer {}

#[TransformerField('relatedData', type: NotAFractalTransformer::class)]
class SchemaWithResolvedRefTransformer {}

/** @return array<string, OA\Property> */
function transformerPropertiesByName(OA\Schema $schema): array
{
    $out = [];

    foreach ($schema->properties as $property) {
        $out[$property->property] = $property;
    }

    return $out;
}

it('builds an object schema from transformer attributes', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = new SchemaFromTransformer($registry, static fn(): array => [])->build(SchemaBookTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);
    $props = transformerPropertiesByName($schema);

    expect($schema->type)->toBe('object')
        ->and($props)->toHaveKeys(['id', 'title', 'author']);
});

it('applies scalar descriptor fields onto the property', function (): void {
    $registry = new ComponentSchemaRegistry();
    new SchemaFromTransformer($registry, static fn(): array => [])->build(SchemaBookTransformer::class);

    $book = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === 'SchemaBookTransformer');

    expect(transformerPropertiesByName($book)['title']->maxLength)->toBe(120);
});

it('emits an include as a $ref and registers the included transformer', function (): void {
    $registry = new ComponentSchemaRegistry();
    new SchemaFromTransformer($registry, static fn(): array => [])->build(SchemaBookTransformer::class);

    $keys = array_map(static fn(OA\Schema $s): string => $s->schema, $registry->all());
    expect($keys)->toContain('SchemaAuthorTransformer');
});

it('marks default includes as required and non-default as optional', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = new SchemaFromTransformer($registry, static fn(): array => [])->build(SchemaBookTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === $key);

    expect($schema->required)->toContain('author');
});

it('exposes buildRef returning a qualified components ref', function (): void {
    $registry = new ComponentSchemaRegistry();
    $ref = new SchemaFromTransformer($registry, static fn(): array => [])->buildRef(SchemaBookTransformer::class);

    expect($ref)->toBe('#/components/schemas/SchemaBookTransformer');
});

it('resolves non-transformer class refs via injected RefSchemaResolver', function (): void {
    $registry = new ComponentSchemaRegistry();
    $customResolver = new class () implements RefSchemaResolver {
        public function resolveRef(string $class): ?string
        {
            if ($class === NotAFractalTransformer::class) {
                return '#/components/schemas/CustomRef';
            }

            return null;
        }
    };

    new SchemaFromTransformer($registry, static fn(): array => [$customResolver])->build(SchemaWithResolvedRefTransformer::class);

    $schema = array_find($registry->all(), static fn(OA\Schema $s): bool => $s->schema === 'SchemaWithResolvedRefTransformer');
    $props = transformerPropertiesByName($schema);

    expect($props['relatedData']->ref)->toBe('#/components/schemas/CustomRef');
});
