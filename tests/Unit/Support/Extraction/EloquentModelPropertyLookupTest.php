<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Support\Types\TypeNodeToSchema;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi');

function modelPropertyReader(): EloquentModelToSchema
{
    $logger = new NullLogger();

    $registry = new ComponentSchemaRegistry();

    return new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
        typeNodeToSchema: new TypeNodeToSchema(),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
        factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
    );
}

/**
 * @return array<string, mixed>
 */
function lookupProperty(string $name): array
{
    $property = modelPropertyReader()->propertyFor(Article::class, $name);

    expect($property)->not->toBeNull();
    assert($property !== null);

    /** @var array<string, mixed> */
    return json_decode(json_encode($property, JSON_THROW_ON_ERROR), associative: true);
}

it('types a cast property', function (): void {
    expect(lookupProperty('published_at'))
        ->toMatchArray(['type' => 'string', 'format' => 'date-time']);
});

it('types an enum-cast property with its case values', function (): void {
    $property = lookupProperty('status');

    expect($property['type'])->toBe('string')
        ->and($property['enum'])->toContain('draft');
});

it('types a docblock @property', function (): void {
    expect(lookupProperty('title'))->toMatchArray(['type' => 'string']);
});

it('resolves a hidden property — output visibility is decided by the caller', function (): void {
    // `internal_notes` sits in $hidden: the model would not serialize it, but a resource
    // toArray() that names it explicitly does.
    expect(lookupProperty('internal_notes'))->toMatchArray(['type' => 'string']);
});

it('types an appended accessor property', function (): void {
    expect(lookupProperty('reading_time'))->toMatchArray(['type' => 'integer']);
});

it('resolves a @property-read relation to a component $ref', function (): void {
    expect(lookupProperty('author'))->toMatchArray(['$ref' => '#/components/schemas/Author']);
});

it('returns null for a property the model does not know', function (): void {
    expect(modelPropertyReader()->propertyFor(Article::class, 'nonexistent'))->toBeNull();
});

it('types a timestamp column the model declares no explicit metadata for', function (): void {
    $property = modelPropertyReader()->propertyFor(Article::class, 'created_at');

    expect($property)->not->toBeNull()
        ->and($property->type)->toBe(['string', 'null'])
        ->and($property->format)->toBe('date-time');
});

it('returns null for a timestamp column when $timestamps is disabled', function (): void {
    $property = modelPropertyReader()->propertyFor(
        \Radiergummi\OpenApi\Tests\Fixtures\Models\UntimestampedArticle::class,
        'created_at',
    );

    expect($property)->toBeNull();
});

it('types a list-shaped array-cast property as an array of its element type', function (): void {
    $property = modelPropertyReader()->propertyFor(
        \Radiergummi\OpenApi\Tests\Fixtures\Models\JsonColumnArticle::class,
        'aliases',
    );

    expect($property)->not->toBeNull()
        ->and(json_decode(json_encode($property, JSON_THROW_ON_ERROR), associative: true))
        ->toEqual(['property' => 'aliases', 'type' => 'array', 'items' => ['type' => 'string']]);
});

it('types a class-form AsCollection cast via its @property generic on the lookup path (#252)', function (): void {
    $property = modelPropertyReader()->propertyFor(
        \Radiergummi\OpenApi\Tests\Fixtures\Models\ClassFormCastArticle::class,
        'tags',
    );

    expect($property)->not->toBeNull()
        ->and(json_decode(json_encode($property, JSON_THROW_ON_ERROR), associative: true))
        ->toEqual(['property' => 'tags', 'type' => 'array', 'items' => ['type' => 'string']]);
});

it('defers a custom-cast column to its @property tag on the lookup path (#252)', function (): void {
    $property = modelPropertyReader()->propertyFor(
        \Radiergummi\OpenApi\Tests\Fixtures\Models\ClassFormCastArticle::class,
        'custom',
    );

    expect($property)->not->toBeNull()
        ->and(json_decode(json_encode($property, JSON_THROW_ON_ERROR), associative: true))
        ->toEqual(['property' => 'custom', 'type' => 'string']);
});

it('memoises the model metadata across lookups', function (): void {
    $reader = modelPropertyReader();

    $first = $reader->propertyFor(Article::class, 'title');
    $second = $reader->propertyFor(Article::class, 'subtitle');

    expect($first)->toBeInstanceOf(OA\Property::class)
        ->and($second)->toBeInstanceOf(OA\Property::class);
});
