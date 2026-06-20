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
use Radiergummi\OpenApi\Tests\Fixtures\Models\ClassFormCastArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\DescribedArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\JsonColumnArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\UntimestampedArticle;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function Radiergummi\OpenApi\is_undefined;

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

    expect($property['type'])
        ->toBe('string')
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

    expect($property)->not
        ->toBeNull()
        ->and($property->type)->toBe(['string', 'null'])
        ->and($property->format)->toBe('date-time');
});

it('returns null for a timestamp column when $timestamps is disabled', function (): void {
    $property = modelPropertyReader()->propertyFor(
        UntimestampedArticle::class,
        'created_at',
    );

    expect($property)->toBeNull();
});

it('types a list-shaped array-cast property as an array of its element type', function (): void {
    $property = modelPropertyReader()->propertyFor(
        JsonColumnArticle::class,
        'aliases',
    );

    expect($property)->not
        ->toBeNull()
        ->and(json_decode(json_encode($property, JSON_THROW_ON_ERROR), associative: true))
        ->toEqual(['property' => 'aliases', 'type' => 'array', 'items' => ['type' => 'string']]);
});

it('types a class-form AsCollection cast via its @property generic on the lookup path (#252)', function (): void {
    $property = modelPropertyReader()->propertyFor(
        ClassFormCastArticle::class,
        'tags',
    );

    expect($property)->not
        ->toBeNull()
        ->and(json_decode(json_encode($property, JSON_THROW_ON_ERROR), associative: true))
        ->toEqual(['property' => 'tags', 'type' => 'array', 'items' => ['type' => 'string']]);
});

it('defers a custom-cast column to its @property tag on the lookup path (#252)', function (): void {
    $property = modelPropertyReader()->propertyFor(
        ClassFormCastArticle::class,
        'custom',
    );

    expect($property)->not
        ->toBeNull()
        ->and(json_decode(json_encode($property, JSON_THROW_ON_ERROR), associative: true))
        ->toEqual(['property' => 'custom', 'type' => 'string']);
});

it('captures a @property trailing description', function (): void {
    $property = modelPropertyReader()->propertyFor(DescribedArticle::class, 'email');

    expect($property)->not->toBeNull();
    assert($property !== null);

    expect($property->description)->toBe('The primary contact email.');
});

it('emits no description for a @property without trailing text', function (): void {
    $property = modelPropertyReader()->propertyFor(DescribedArticle::class, 'name');

    expect($property)->not->toBeNull();
    assert($property !== null);

    expect(is_undefined($property->description))->toBeTrue();
});

it('captures a @property description with no surrounding whitespace', function (): void {
    $property = modelPropertyReader()->propertyFor(DescribedArticle::class, 'title');

    expect($property)->not->toBeNull();
    assert($property !== null);

    expect($property->description)->toBe('Surrounded by spaces.');
});

it('captures a @property-read trailing description', function (): void {
    $property = modelPropertyReader()->propertyFor(DescribedArticle::class, 'slug');

    expect($property)->not->toBeNull();
    assert($property !== null);

    expect($property->description)->toBe('URL-safe identifier.');
});

it('captures a @property description on a cast column', function (): void {
    $property = modelPropertyReader()->propertyFor(DescribedArticle::class, 'published_at');

    expect($property)->not->toBeNull();
    assert($property !== null);

    expect($property->type)
        ->toBe('string')
        ->and($property->format)->toBe('date-time')
        ->and($property->description)->toBe('When the article went live.');
});

it('keeps the @property prose as a description sibling of a relation $ref', function (): void {
    $property = modelPropertyReader()->propertyFor(DescribedArticle::class, 'author');

    expect($property)->not->toBeNull();
    assert($property !== null);

    // A relation $ref's field description exists nowhere else; OAS 3.1 allows it as a sibling.
    expect($property->ref)
        ->toBe('#/components/schemas/Author')
        ->and($property->description)->toBe("The article's primary author.");
});

it('lets a documented-enum case list pre-empt the @property description', function (): void {
    $property = modelPropertyReader()->propertyFor(DescribedArticle::class, 'status');

    expect($property)->not->toBeNull();
    assert($property !== null);

    // The backed-enum case list (more specific than free prose) wins over the @property text.
    expect($property->description)
        ->toContain('`draft`: The article is still being written.')
        ->not->toBe('A described status tag.');
});

it('memoises the model metadata across lookups', function (): void {
    $reader = modelPropertyReader();

    $first = $reader->propertyFor(Article::class, 'title');
    $second = $reader->propertyFor(Article::class, 'subtitle');

    expect($first)
        ->toBeInstanceOf(OA\Property::class)
        ->and($second)->toBeInstanceOf(OA\Property::class);
});
