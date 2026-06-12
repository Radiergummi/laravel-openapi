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
use Radiergummi\OpenApi\Support\Types\TypeNodeToSchema;
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
        jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
        typeNodeToSchema: new TypeNodeToSchema(),
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

it('maps an enum cast to a $ref into a shared reusable enum component', function (): void {
    $registry = new ComponentSchemaRegistry();
    $logger = new NullLogger();

    $reader = new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
        typeNodeToSchema: new TypeNodeToSchema(),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
    );

    $reader->build(Article::class);

    $article = json_decode(json_encode(collect($registry->all())->firstWhere('schema', 'Article')), true);
    $component = json_decode(json_encode(collect($registry->all())->firstWhere('schema', 'ArticleStatus')), true);

    expect($article['properties']['status']['$ref'])->toBe('#/components/schemas/ArticleStatus')
        ->and($component['type'])->toBe('string')
        ->and($component['enum'])->toBe(['draft', 'published']);
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
        jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
        typeNodeToSchema: new TypeNodeToSchema(),
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

it('types created_at/updated_at as nullable date-time when the model uses timestamps', function (): void {
    $schema = buildModelSchema(Article::class);

    expect(modelProperty($schema, 'created_at')->type)->toBe(['string', 'null'])
        ->and(modelProperty($schema, 'created_at')->format)->toBe('date-time')
        ->and(modelProperty($schema, 'updated_at')->type)->toBe(['string', 'null'])
        ->and(modelProperty($schema, 'updated_at')->format)->toBe('date-time');
});

it('never marks default-typed timestamp columns required', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['required'] ?? [])->not->toContain('created_at')
        ->and($schema['required'] ?? [])->not->toContain('updated_at');
});

it('omits timestamp columns when $timestamps is disabled', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\UntimestampedArticle::class);

    expect($schema['properties'] ?? [])->not->toHaveKey('created_at')
        ->and($schema['properties'] ?? [])->not->toHaveKey('updated_at');
});

it('respects renamed and disabled timestamp columns via the framework constants', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\CustomTimestampColumnsArticle::class);

    expect($schema['properties'])->toHaveKey('creation_date')
        ->and($schema['properties']['creation_date']['format'])->toBe('date-time')
        ->and($schema['properties'])->not->toHaveKey('created_at')
        ->and($schema['properties'] ?? [])->not->toHaveKey('updated_at');
});

it('lets an explicit @property tag or cast win over the timestamp default', function (): void {
    $schema = buildModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\OverriddenTimestampsArticle::class);

    // `@property Carbon $created_at` is non-nullable: plain string, required.
    expect(modelProperty($schema, 'created_at')->type)->toBe('string')
        ->and(modelProperty($schema, 'created_at')->format)->toBe('date-time')
        // The explicit `date` cast beats the date-time default.
        ->and(modelProperty($schema, 'updated_at')->type)->toBe('string')
        ->and(modelProperty($schema, 'updated_at')->format)->toBe('date');

    $required = json_decode(json_encode($schema), associative: true)['required'] ?? [];

    expect($required)->toContain('created_at');
});

it('types array/json/collection casts as lists when the @property generic is list-shaped', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\JsonColumnArticle::class);
    $properties = $schema['properties'];

    expect($properties['aliases'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['tags'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['flags'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['ranks'])->toEqual(['type' => 'array', 'items' => ['type' => 'integer']]);
});

it('keeps map-shaped and untagged array casts as objects', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\JsonColumnArticle::class);
    $properties = $schema['properties'];

    expect($properties['options'])->toEqual(['type' => 'object'])
        ->and($properties['meta'])->toEqual(['type' => 'object']);
});

it('keeps the object cast an object even when the tag is list-shaped', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\JsonColumnArticle::class);

    expect($schema['properties']['settings'])->toEqual(['type' => 'object']);
});

it('finds the list shape through a nullable @property tag', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\JsonColumnArticle::class);

    expect($schema['properties']['maybe_tags'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('emits an array with unconstrained items when the list element is not a scalar keyword', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\JsonColumnArticle::class);

    // The element type is unknown, but swagger-php validation requires items on every array.
    expect($schema['properties']['milestones'])->toEqual(['type' => 'array', 'items' => []]);
});

it('types class-form JSON casts (AsCollection / AsArrayObject / AsEncryptedCollection) via the @property generic (#252)', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\ClassFormCastArticle::class);
    $properties = $schema['properties'];

    // List-shaped @property → array of the scalar element.
    expect($properties['tags'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['labels'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['secrets'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        // Map-shaped @property keeps the conservative object default.
        ->and($properties['options'])->toEqual(['type' => 'object']);
});

it('types the AsStringable class-form cast as a string (#252)', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\ClassFormCastArticle::class);

    expect($schema['properties']['slug'])->toEqual(['type' => 'string']);
});

it('lets the @property tag type a column behind an unrecognised custom cast instead of swallowing it (#252)', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\ClassFormCastArticle::class);
    $properties = $schema['properties'];

    // A custom CastsAttributes is unknowable at Tier 0, but its @property tag still has a say.
    expect($properties['custom'])->toEqual(['type' => 'string'])
        // No tag → genuinely untyped, the unchanged fallback for an opaque cast.
        ->and($properties['custom_untyped'])->toEqual([]);
});

it('degrades to an unknown-shape schema for a non-instantiable model instead of throwing', function (): void {
    // Regression for #100: `new $modelClass()` on an abstract model throws an Error, which the
    // resolver fault boundary does not catch. The reader must guard instantiation and fall back.
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\AbstractModel::class);

    expect($schema['type'])->toBe('object')
        ->and($schema)->not->toHaveKey('properties');
});

it('resolves array-shape @property annotations into object schemas (#127)', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\ShapedArticle::class);
    $properties = $schema['properties'];

    // Sealed shape → object with required keys.
    expect($properties['coordinates']['type'])->toBe('object')
        ->and($properties['coordinates']['properties']['lat']['type'])->toBe('number')
        ->and($properties['coordinates']['required'])->toBe(['lat', 'lng']);

    // Optional key omitted from required.
    expect($properties['address']['properties'])->toHaveKeys(['street', 'unit'])
        ->and($properties['address']['required'])->toBe(['street']);

    // Nested shape → nested object.
    expect($properties['envelope']['properties']['meta']['properties']['source']['type'])->toBe('string');

    // list<array{…}> → array of objects.
    expect($properties['tags']['type'])->toBe('array')
        ->and($properties['tags']['items']['type'])->toBe('object')
        ->and($properties['tags']['items']['properties']['id']['type'])->toBe('integer');
});

it('resolves a non-model class @property via JsonSchemaFromType', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\ShapedArticle::class);

    // DateTimeImmutable is a class, not a Model, so it flows through JsonSchemaFromType.
    expect($schema['properties']['observed_at'])->toBe(['type' => 'string', 'format' => 'date-time']);
});

it('falls back to an empty property for an unresolvable @property type', function (): void {
    $schema = readModelSchema(\Radiergummi\OpenApi\Tests\Fixtures\Models\ShapedArticle::class);

    // `mixed` resolves to no schema, so the property is present but untyped.
    expect($schema['properties'])->toHaveKey('payload')
        ->and($schema['properties']['payload'])->toBe([]);
});
