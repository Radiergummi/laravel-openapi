<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi');

/**
 * Builds the model's schema and returns the live OA\Schema object. Assert on the object's
 * properties to see the OAS 3.1 type unions (`type: ['…', 'null']`) — swagger-php's raw
 * json_encode down-converts those to the 3.0 `nullable: true` form, so {@see readModelSchema()}
 * (the array view) is only suitable for version-agnostic assertions.
 *
 * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
 */
function buildModelSchema(string $modelClass): OA\Schema
{
    $registry = new ComponentSchemaRegistry();
    $logger = new NullLogger();

    $reader = new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
    );

    $key = $reader->build($modelClass);

    /** @var OA\Schema $schema */
    $schema = collect($registry->all())->firstWhere('schema', $key);

    return $schema;
}

/**
 * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
 *
 * @return array<string, mixed>
 */
function readModelSchema(string $modelClass): array
{
    return json_decode(json_encode(buildModelSchema($modelClass)), associative: true);
}

/**
 * Returns the named property object from a built model schema.
 */
function modelProperty(OA\Schema $schema, string $name): OA\Property
{
    /** @var OA\Property $property */
    $property = collect($schema->properties)->firstWhere('property', $name);

    return $property;
}

it('maps datetime casts to string/date-time', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['published_at']['type'])->toBe('string')
        ->and($schema['properties']['published_at']['format'])->toBe('date-time');
});

it('excludes $hidden fields', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['properties'])->not->toHaveKey('internal_notes');
});

it('includes $appends names in the property set', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['properties'])->toHaveKey('reading_time');
});

it('types scalar @property fields and expresses nullable ones via the OAS 3.1 idiom', function (): void {
    $schema = buildModelSchema(Article::class);

    // OAS 3.1 removed `nullable`; a nullable scalar widens its `type` to include 'null'. Asserted
    // on the object because swagger-php's json_encode down-converts the union to `nullable: true`.
    expect(modelProperty($schema, 'title')->type)->toBe('string')
        ->and(modelProperty($schema, 'subtitle')->type)->toBe(['string', 'null']);
});

it('marks non-nullable @property fields required and omits nullable ones', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['required'] ?? [])->toContain('title')
        ->and($schema['required'] ?? [])->not->toContain('subtitle')
        ->and($schema['required'] ?? [])->not->toContain('internal_notes');
});

it('marks a cast-typed field required when its @property is non-nullable', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['required'] ?? [])->toContain('published_at');
});

it('maps an enum cast to an inline enum schema', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['properties']['status']['type'])->toBe('string')
        ->and($schema['properties']['status']['enum'])->toBe(['draft', 'published']);
});

it('types an $appends accessor from its return type', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['properties']['reading_time']['type'])->toBe('integer');
});

it('restricts the property set to $visible when the allow-list is non-empty', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\VisibleArticle::class);

    expect(array_keys($schema['properties'] ?? []))->toContain('title')
        ->and(array_keys($schema['properties'] ?? []))->toContain('reading_time')
        ->and($schema['properties'] ?? [])->not->toHaveKey('secret');
});

it('emits a $ref for a @property-read model relation and registers the nested component', function (): void {
    $registry = new ComponentSchemaRegistry();
    $logger = new NullLogger();

    $reader = new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
    );

    $reader->build(Article::class);

    $article = json_decode(json_encode(collect($registry->all())->firstWhere('schema', 'Article')), true);
    $authorRegistered = collect($registry->all())->firstWhere('schema', 'Author');

    expect($article['properties']['author']['$ref'])->toBe('#/components/schemas/Author')
        ->and($authorRegistered)->not->toBeNull();
});

it('wraps a nullable relation $ref in oneOf (OAS 3.1) rather than a dropped sibling nullable', function (): void {
    $schema = readModelSchema(Article::class);

    // A bare $ref ignores sibling keywords in OAS 3.1, so nullability must be expressed as a
    // oneOf of the ref and a null type — not `{$ref, nullable: true}`.
    $editor = $schema['properties']['editor'];

    expect($editor)->not->toHaveKey('$ref')
        ->and($editor)->not->toHaveKey('nullable')
        ->and($editor['oneOf'])->toBe([
            ['$ref' => '#/components/schemas/Author'],
            ['type' => 'null'],
        ]);
});
