<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\MigrationColumnReader;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Models\AbstractModel;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\AttributesDefaultArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Models\ClassFormCastArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\ConflictedMetadataArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\CustomTimestampColumnsArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\DescribedArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\EncryptedCastArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\FactoryArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\JsonColumnArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\OverriddenTimestampsArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\ShapedArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\UntimestampedArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\VisibleArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Widget;
use ReflectionMethod;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function Radiergummi\OpenApi\is_undefined;

uses()->group('openapi');

/**
 * Builds a reader wired to the fixture migrations directory, for tests that need the reader itself
 * (repeated lookups, log assertions) rather than a finished schema.
 */
function modelSchemaReader(
    ComponentSchemaRegistry $registry,
    bool $readMigrationColumns = true,
    ?LoggerInterface $logger = null,
): EloquentModelToSchema {
    $logger ??= new NullLogger();

    return new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
        factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
        migrationColumnReader: new MigrationColumnReader(
            migrationsDirectory: dirname(__DIR__, 3) . '/Fixtures/Migrations',
            logger: $logger,
        ),
        readMigrationColumns: $readMigrationColumns,
    );
}

/**
 * Builds the model's schema and returns the live OA\Schema object. Assert on the object's
 * properties to see the OAS 3.1 type unions (`type: ['…', 'null']`) — swagger-php's raw
 * json_encode down-converts those to the 3.0 `nullable: true` form, so {@see readModelSchema()}
 * (the array view) is only suitable for version-agnostic assertions.
 *
 * @param class-string<Model> $modelClass
 */
function buildModelSchema(
    string $modelClass,
    bool $readMigrationColumns = true,
    ?LoggerInterface $logger = null,
): OA\Schema {
    $registry = new ComponentSchemaRegistry();
    $reader = modelSchemaReader($registry, $readMigrationColumns, $logger);

    $key = $reader->build($modelClass);

    /** @var OA\Schema $schema */
    $schema = collect($registry->all())->firstWhere('schema', $key);

    return $schema;
}

/**
 * @param class-string<Model> $modelClass
 *
 * @return array<string, mixed>
 */
function readModelSchema(string $modelClass): array
{
    return json_decode(json_encode(buildModelSchema($modelClass)), associative: true);
}

/**
 * The context of each discarded-keyword warning a recording logger captured, ignoring anything the
 * migration reader logged along the way.
 *
 * @param AbstractLogger&object{records: list<array{
 *     level: mixed, message: string, context: array<string, mixed>
 * }>} $logger
 *
 * @return array<int, array<string, mixed>>
 */
function discardedKeywordWarnings(AbstractLogger $logger): array
{
    return collect($logger->records)
        ->filter(static fn(array $record): bool => str_contains($record['message'], 'inapplicable'))
        ->map(static fn(array $record): array => $record['context'])
        ->values()
        ->all();
}

/**
 * Runs the keyword-applicability pass over a hand-built property, in place.
 *
 * Reaches past the public surface deliberately: the pass must refuse to prune a format it does not
 * recognise, and no producer feeding this class emits one, so the guarantee has no reachable input
 * to assert it through.
 */
function dropInapplicableKeywordsOn(OA\Property $property): void
{
    $reader = modelSchemaReader(new ComponentSchemaRegistry());

    (new ReflectionMethod($reader, 'dropInapplicableKeywords'))
        ->invoke($reader, ConflictedMetadataArticle::class, $property);
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

    expect($schema['type'])
        ->toBe('object')
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
    expect(modelProperty($schema, 'title')->type)
        ->toBe('string')
        ->and(modelProperty($schema, 'subtitle')->type)->toBe(['string', 'null']);
});

it('marks non-nullable @property fields required and omits nullable ones', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['required'] ?? [])
        ->toContain('title')
        ->and($schema['required'] ?? [])->not
        ->toContain('subtitle')
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
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
        factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
    );

    $reader->build(Article::class);

    $article = json_decode(json_encode(collect($registry->all())->firstWhere('schema', 'Article')), true);
    $component = json_decode(json_encode(collect($registry->all())->firstWhere('schema', 'ArticleStatus')), true);

    expect($article['properties']['status']['$ref'])
        ->toBe('#/components/schemas/ArticleStatus')
        ->and($component['type'])->toBe('string')
        ->and($component['enum'])->toBe(['draft', 'published']);
});

it('types an $appends accessor from its return type', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['properties']['reading_time']['type'])->toBe('integer');
});

it('restricts the property set to $visible when the allow-list is non-empty', function (): void {
    $schema = readModelSchema(VisibleArticle::class);

    expect(array_keys($schema['properties'] ?? []))
        ->toContain('title')
        ->and(array_keys($schema['properties'] ?? []))->toContain('reading_time')
        ->and($schema['properties'] ?? [])->not->toHaveKey('secret');
});

it('emits a $ref for a @property-read model relation and registers the nested component', function (): void {
    $registry = new ComponentSchemaRegistry();
    $logger = new NullLogger();

    $reader = new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
        factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
    );

    $reader->build(Article::class);

    $article = json_decode(json_encode(collect($registry->all())->firstWhere('schema', 'Article')), true);
    $authorRegistered = collect($registry->all())->firstWhere('schema', 'Author');

    expect($article['properties']['author']['$ref'])
        ->toBe('#/components/schemas/Author')
        ->and($authorRegistered)->not->toBeNull();
});

it('wraps a nullable relation $ref in oneOf (OAS 3.1) rather than a dropped sibling nullable', function (): void {
    $schema = readModelSchema(Article::class);

    // A bare $ref ignores sibling keywords in OAS 3.1, so nullability must be expressed as a
    // oneOf of the ref and a null type — not `{$ref, nullable: true}`.
    $editor = $schema['properties']['editor'];

    expect($editor)->not
        ->toHaveKey('$ref')
        ->and($editor)->not
        ->toHaveKey('nullable')
        ->and($editor['oneOf'])->toBe([
            ['$ref' => '#/components/schemas/Author'],
            ['type' => 'null'],
        ]);
});

it('types created_at/updated_at as nullable date-time when the model uses timestamps', function (): void {
    $schema = buildModelSchema(Article::class);

    expect(modelProperty($schema, 'created_at')->type)
        ->toBe(['string', 'null'])
        ->and(modelProperty($schema, 'created_at')->format)->toBe('date-time')
        ->and(modelProperty($schema, 'updated_at')->type)->toBe(['string', 'null'])
        ->and(modelProperty($schema, 'updated_at')->format)->toBe('date-time');
});

it('never marks default-typed timestamp columns required', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['required'] ?? [])->not
        ->toContain('created_at')
        ->and($schema['required'] ?? [])->not->toContain('updated_at');
});

it('omits timestamp columns when $timestamps is disabled', function (): void {
    $schema = readModelSchema(UntimestampedArticle::class);

    expect($schema['properties'] ?? [])->not
        ->toHaveKey('created_at')
        ->and($schema['properties'] ?? [])->not->toHaveKey('updated_at');
});

it('respects renamed and disabled timestamp columns via the framework constants', function (): void {
    $schema = readModelSchema(CustomTimestampColumnsArticle::class);

    expect($schema['properties'])
        ->toHaveKey('creation_date')
        ->and($schema['properties']['creation_date']['format'])->toBe('date-time')
        ->and($schema['properties'])->not
        ->toHaveKey('created_at')
        ->and($schema['properties'] ?? [])->not->toHaveKey('updated_at');
});

it('lets an explicit @property tag or cast win over the timestamp default', function (): void {
    $schema = buildModelSchema(OverriddenTimestampsArticle::class);

    // `@property Carbon $created_at` is non-nullable: plain string, required.
    expect(modelProperty($schema, 'created_at')->type)
        ->toBe('string')
        ->and(modelProperty($schema, 'created_at')->format)->toBe('date-time')
        // The explicit `date` cast beats the date-time default.
        ->and(modelProperty($schema, 'updated_at')->type)->toBe('string')
        ->and(modelProperty($schema, 'updated_at')->format)->toBe('date');

    $required = json_decode(json_encode($schema), associative: true)['required'] ?? [];

    expect($required)->toContain('created_at');
});

it('types array/json/collection casts as lists when the @property generic is list-shaped', function (): void {
    $schema = readModelSchema(JsonColumnArticle::class);
    $properties = $schema['properties'];

    expect($properties['aliases'])
        ->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['tags'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['flags'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['ranks'])->toEqual(['type' => 'array', 'items' => ['type' => 'integer']]);
});

it('treats single-arg array<T>, non-empty-list<T>, and non-empty-array<T> casts as lists (#284)', function (): void {
    // Cast path must agree with the pure-tag path: single-argument array<T> is a list,
    // not an object. Before the fix, listValueType() returned null for these forms,
    // causing jsonCastDefinition() to fall back to type: object.
    $schema = readModelSchema(JsonColumnArticle::class);
    $properties = $schema['properties'];

    expect($properties['labels'])
        ->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['scores'])->toEqual(['type' => 'array', 'items' => ['type' => 'integer']])
        ->and($properties['slugs'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('keeps map-shaped and untagged array casts as objects', function (): void {
    $schema = readModelSchema(JsonColumnArticle::class);
    $properties = $schema['properties'];

    expect($properties['options'])
        ->toEqual(['type' => 'object'])
        ->and($properties['meta'])->toEqual(['type' => 'object']);
});

it('keeps the object cast an object even when the tag is list-shaped', function (): void {
    $schema = readModelSchema(JsonColumnArticle::class);

    expect($schema['properties']['settings'])->toEqual(['type' => 'object']);
});

it('finds the list shape through a nullable @property tag', function (): void {
    $schema = readModelSchema(JsonColumnArticle::class);

    expect($schema['properties']['maybe_tags'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('emits an array with unconstrained items when the list element is not a scalar keyword', function (): void {
    $schema = readModelSchema(JsonColumnArticle::class);

    // The element type is unknown, but swagger-php validation requires items on every array.
    expect($schema['properties']['milestones'])->toEqual(['type' => 'array', 'items' => []]);
});

it(
    'types class-form JSON casts (AsCollection / AsArrayObject / AsEncryptedCollection) via the @property generic (#252)',
    function (): void {
        $schema = readModelSchema(ClassFormCastArticle::class);
        $properties = $schema['properties'];

        // List-shaped @property → array of the scalar element.
        expect($properties['tags'])
            ->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
            ->and($properties['labels'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
            ->and($properties['secrets'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
            // Map-shaped @property keeps the conservative object default.
            ->and($properties['options'])->toEqual(['type' => 'object']);
    },
);

it('types the AsStringable class-form cast as a string (#252)', function (): void {
    $schema = readModelSchema(ClassFormCastArticle::class);

    expect($schema['properties']['slug'])->toEqual(['type' => 'string']);
});

it(
    'lets the @property tag type a column behind an unrecognised custom cast instead of swallowing it (#252)',
    function (): void {
        $schema = readModelSchema(ClassFormCastArticle::class);
        $properties = $schema['properties'];

        // A custom CastsAttributes is unknowable at Tier 0, but its @property tag still has a say.
        expect($properties['custom'])
            ->toEqual(['type' => 'string'])
            // No tag → genuinely untyped, the unchanged fallback for an opaque cast.
            ->and($properties['custom_untyped'])->toEqual([]);
    },
);

it('degrades to an unknown-shape schema for a non-instantiable model instead of throwing', function (): void {
    // Regression for #100: `new $modelClass()` on an abstract model throws an Error, which the
    // resolver fault boundary does not catch. The reader must guard instantiation and fall back.
    $schema = readModelSchema(AbstractModel::class);

    expect($schema['type'])
        ->toBe('object')
        ->and($schema)->not->toHaveKey('properties');
});

it('resolves array-shape @property annotations into object schemas (#127)', function (): void {
    $schema = readModelSchema(ShapedArticle::class);
    $properties = $schema['properties'];

    // Sealed shape → object with required keys.
    expect($properties['coordinates']['type'])
        ->toBe('object')
        ->and($properties['coordinates']['properties']['lat']['type'])->toBe('number')
        ->and($properties['coordinates']['required'])->toBe(['lat', 'lng']);

    // Optional key omitted from required.
    expect($properties['address']['properties'])
        ->toHaveKeys(['street', 'unit'])
        ->and($properties['address']['required'])->toBe(['street']);

    // Nested shape → nested object.
    expect($properties['envelope']['properties']['meta']['properties']['source']['type'])->toBe('string');

    // list<array{…}> → array of objects.
    expect($properties['tags']['type'])
        ->toBe('array')
        ->and($properties['tags']['items']['type'])->toBe('object')
        ->and($properties['tags']['items']['properties']['id']['type'])->toBe('integer');
});

it('resolves a non-model class @property via JsonSchemaFromType', function (): void {
    $schema = readModelSchema(ShapedArticle::class);

    // DateTimeImmutable is a class, not a Model, so it flows through JsonSchemaFromType.
    expect($schema['properties']['observed_at'])->toBe(['type' => 'string', 'format' => 'date-time']);
});

it('falls back to an empty property for an unresolvable @property type', function (): void {
    $schema = readModelSchema(ShapedArticle::class);

    // `mixed` resolves to no schema, so the property is present but untyped.
    expect($schema['properties'])
        ->toHaveKey('payload')
        ->and($schema['properties']['payload'])->toBe([]);
});

it(
    'maps encrypted:array/json/collection casts to the same schema as their non-encrypted counterparts (#283)',
    function (): void {
        $schema = readModelSchema(EncryptedCastArticle::class);
        $properties = $schema['properties'];

        // Without a list-shaped @property tag, these default to object (same as plain array/json/collection).
        expect($properties['settings'])
            ->toEqual(['type' => 'object'])
            ->and($properties['preferences'])->toEqual(['type' => 'object'])
            ->and($properties['data'])->toEqual(['type' => 'object']);
    },
);

it('maps encrypted:object cast to type: object (#283)', function (): void {
    $schema = readModelSchema(EncryptedCastArticle::class);

    expect($schema['properties']['payload'])->toEqual(['type' => 'object']);
});

it('keeps bare encrypted cast as type: string (#283)', function (): void {
    $schema = readModelSchema(EncryptedCastArticle::class);

    expect($schema['properties']['secret'])->toEqual(['type' => 'string']);
});

it('respects @property list hints for encrypted:array and encrypted:collection casts (#283)', function (): void {
    $schema = readModelSchema(EncryptedCastArticle::class);
    $properties = $schema['properties'];

    // A list-shaped @property tag disambiguates to an array, exactly as with plain array/collection.
    expect($properties['tags'])
        ->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['labels'])->toEqual(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['options'])->toEqual(['type' => 'object']);
});

it('seeds property examples from the model factory definition', function (): void {
    $schema = buildModelSchema(FactoryArticle::class);

    expect(modelProperty($schema, 'title')->example)
        ->toBeString()
        ->and(modelProperty($schema, 'views')->example)->toBeInt();
});

it('leaves properties without an example when the model has no factory', function (): void {
    $schema = buildModelSchema(Author::class);

    // Generator::UNDEFINED marks an unset swagger-php field.
    expect(modelProperty($schema, 'name')->example)->toBe(Generator::UNDEFINED);
});

it('captures @property trailing descriptions in the schema', function (): void {
    $schema = buildModelSchema(DescribedArticle::class);

    expect(modelProperty($schema, 'email')->description)
        ->toBe('The primary contact email.')
        ->and(modelProperty($schema, 'slug')->description)->toBe('URL-safe identifier.')
        ->and(modelProperty($schema, 'published_at')->description)->toBe('When the article went live.');
});

it('emits no description for a schema @property without trailing text', function (): void {
    $schema = buildModelSchema(DescribedArticle::class);

    expect(is_undefined(modelProperty($schema, 'name')->description))->toBeTrue();
});

it('keeps the @property prose as a description sibling of a $ref enum property', function (): void {
    // schemaFor promotes the backed enum to a component $ref; OpenAPI 3.1 allows a description
    // sibling to $ref, so the tag prose is set without clobbering the component's case-list.
    $status = modelProperty(buildModelSchema(DescribedArticle::class), 'status');

    expect($status->ref)
        ->toBe('#/components/schemas/DescribedStatus')
        ->and($status->description)->toBe('A described status tag.');
});

it('keeps the @property prose as a description sibling of a $ref relation property', function (): void {
    // A relation $ref's field description exists nowhere else, so the trailing prose must survive.
    $author = modelProperty(buildModelSchema(DescribedArticle::class), 'author');

    expect($author->ref)
        ->toBe('#/components/schemas/Author')
        ->and($author->description)->toBe("The article's primary author.");
});

it('enriches an uncast string column with the migration format', function (): void {
    // last_ip has no cast; the migration's ipAddress() supplies format: ip and nullable.
    $property = modelProperty(buildModelSchema(Widget::class), 'last_ip');

    expect($property->format)->toBe('ip')
        ->and($property->type)->toBe(['string', 'null']);
});

it('reads maxLength from the migration for an uncast column', function (): void {
    $property = modelProperty(buildModelSchema(Widget::class), 'name');

    expect($property->maxLength)->toBe(120);
});

it('lets a cast win over the migration type, enriching only undefined fields', function (): void {
    // decimal:2 cast yields type: string; the decimal(8,2) migration would say number. The cast type
    // survives, and the migration's numeric multipleOf is discarded as inapplicable to a string.
    // The warning is asserted too: absent multipleOf alone would also hold if none were ever written.
    $logger = recordingLogger();
    $property = modelProperty(buildModelSchema(Widget::class, logger: $logger), 'price');

    expect($property->type)->toBe('string')
        ->and(is_undefined($property->multipleOf))->toBeTrue()
        ->and(discardedKeywordWarnings($logger))->toContain([
            'model' => Widget::class,
            'property' => 'price',
            'keyword' => 'multipleOf',
            'type' => ['string'],
        ]);
});

it('relaxes an uncast unsigned column to minimum 0', function (): void {
    $property = modelProperty(buildModelSchema(Widget::class), 'quantity');

    expect($property->minimum)->toBe(0);
});

it('reads an enum column into the property enum', function (): void {
    $property = modelProperty(buildModelSchema(Widget::class), 'size');

    expect($property->enum)->toBe(['small', 'medium', 'large']);
});

it('reads a migration comment as the property description', function (): void {
    $property = modelProperty(buildModelSchema(Widget::class), 'notes');

    expect($property->description)->toBe('Free-form operator notes.');
});

it('reads a literal migration default', function (): void {
    $property = modelProperty(buildModelSchema(Widget::class), 'label');

    expect($property->default)->toBe('unlabelled');
});

it('leaves a json-cast column untouched by the migration object type', function (): void {
    // configuration is cast to array and the migration declares json; both agree on object,
    // so the result is an object either way (cast wins on type).
    $property = modelProperty(buildModelSchema(Widget::class), 'configuration');

    expect($property->type)->toBe('object');
});

it('emits the baseline schema when migration reading is disabled', function (): void {
    $property = modelProperty(buildModelSchema(Widget::class, readMigrationColumns: false), 'last_ip');

    expect(is_undefined($property->format))->toBeTrue()
        ->and(is_undefined($property->type))->toBeTrue();
});

it('leaves an enum-cast $ref property untouched by a migration enum column', function (): void {
    // status is cast to a backed enum (a $ref); the migration's enum('status', [...]) must not
    // graft an `enum`/`type` sibling onto the $ref.
    $property = modelProperty(buildModelSchema(Widget::class), 'status');

    expect($property->ref)
        ->toBe('#/components/schemas/ArticleStatus')
        ->and(is_undefined($property->enum))->toBeTrue()
        ->and(is_undefined($property->type))->toBeTrue();
});

it('fills the property default from the model $attributes array', function (): void {
    $schema = buildModelSchema(AttributesDefaultArticle::class);

    expect(modelProperty($schema, 'summary')->default)
        ->toBe('No summary provided.')
        ->and(modelProperty($schema, 'priority')->default)->toBe(0);
});

it('honours an explicit null $attributes default and emits it as default: null', function (): void {
    $property = modelProperty(buildModelSchema(AttributesDefaultArticle::class), 'archived_at');

    // array_key_exists, not isset: an explicit `'archived_at' => null` is a real default of null,
    // distinct from an absent entry, and must serialise as "default": null.
    expect(is_undefined($property->default))->toBeFalse()
        ->and($property->default)->toBeNull();
});

it('lets a migration ->default() outrank the $attributes entry for the same column', function (): void {
    // state has both a migration ->default('published') and an $attributes 'draft'; the migration
    // default is written first and the lower-precedence $attributes read is skipped by is_undefined.
    $property = modelProperty(buildModelSchema(AttributesDefaultArticle::class), 'state');

    expect($property->default)->toBe('published');
});

it('writes no default for a property absent from $attributes', function (): void {
    $property = modelProperty(buildModelSchema(AttributesDefaultArticle::class), 'name');

    expect(is_undefined($property->default))->toBeTrue();
});

it('never grafts an $attributes default onto a $ref property', function (): void {
    // status is an enum-cast $ref; its $attributes entry must not add a sibling default that a
    // bare $ref would ignore in OAS 3.1.
    $property = modelProperty(buildModelSchema(AttributesDefaultArticle::class), 'status');

    expect($property->ref)
        ->toBe('#/components/schemas/ArticleStatus')
        ->and(is_undefined($property->default))->toBeTrue();
});

it('fills the $attributes default with no migration column present', function (): void {
    // The common real-world case: a model declares $attributes defaults but no migration is read,
    // so the column-less path must still fill `default` (and still skip an absent entry).
    $schema = buildModelSchema(AttributesDefaultArticle::class, readMigrationColumns: false);

    expect(modelProperty($schema, 'summary')->default)
        ->toBe('No summary provided.')
        ->and(modelProperty($schema, 'state')->default)->toBe('draft')
        ->and(is_undefined(modelProperty($schema, 'name')->default))->toBeTrue();
});

it('drops a numeric keyword when the resolved type has no numeric member', function (): void {
    // The long-lived-app shape: the key migrated to a ULID (@property string) over an old
    // increments('id') column, whose minimum: 0 is inert on a string.
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'id');

    expect($property->type)->toBe('string')
        ->and(is_undefined($property->minimum))->toBeTrue();
});

it('drops a string length keyword when the resolved type has no string member', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'code');

    expect($property->type)->toBe('integer')
        ->and(is_undefined($property->maxLength))->toBeTrue();
});

it('drops a string pattern keyword when the resolved type has no string member', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'device');

    expect($property->type)->toBe('integer')
        ->and(is_undefined($property->pattern))->toBeTrue();
});

it('drops a string keyword from an array-typed property', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'tags');

    expect($property->type)->toBe('array')
        ->and(is_undefined($property->maxLength))->toBeTrue();
});

it('keeps a keyword the resolved type does apply to', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'score');

    expect($property->type)->toBe('integer')
        ->and($property->minimum)->toBe(0);
});

it('drops every inapplicable keyword on a property, not just the first', function (): void {
    // An unsignedDecimal head contributes minimum alongside the scale-derived multipleOf, so the
    // decimal:2 cast's string type leaves both inert; a prune that stopped at the first would keep
    // multipleOf.
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'rate');

    expect($property->type)->toBe('string')
        ->and(is_undefined($property->minimum))->toBeTrue()
        ->and(is_undefined($property->multipleOf))->toBeTrue();
});

it('keeps a keyword while one union member belongs to its type class', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'slug');

    expect($property->type)->toBe(['string', 'null'])
        ->and($property->maxLength)->toBe(20);
});

it('warns for every discarded keyword, naming the model, property and keyword', function (): void {
    $logger = recordingLogger();

    buildModelSchema(ConflictedMetadataArticle::class, logger: $logger);

    $model = ConflictedMetadataArticle::class;

    expect(discardedKeywordWarnings($logger))->toEqualCanonicalizing([
        ['model' => $model, 'property' => 'id', 'keyword' => 'minimum', 'type' => ['string']],
        ['model' => $model, 'property' => 'code', 'keyword' => 'maxLength', 'type' => ['integer']],
        ['model' => $model, 'property' => 'device', 'keyword' => 'pattern', 'type' => ['integer']],
        ['model' => $model, 'property' => 'tags', 'keyword' => 'maxLength', 'type' => ['array']],
        ['model' => $model, 'property' => 'rate', 'keyword' => 'minimum', 'type' => ['string']],
        ['model' => $model, 'property' => 'rate', 'keyword' => 'multipleOf', 'type' => ['string']],
        ['model' => $model, 'property' => 'status', 'keyword' => 'enum', 'type' => ['integer']],
        ['model' => $model, 'property' => 'published_on', 'keyword' => 'format', 'type' => ['integer']],
        ['model' => $model, 'property' => 'flags', 'keyword' => 'enum', 'type' => ['array']],
        ['model' => $model, 'property' => 'reference', 'keyword' => 'format', 'type' => ['integer']],
    ]);
});

it('reports a repeated discarded keyword only once per run', function (): void {
    // propertyFor() is not memoized, so a model reached from fifty routes would otherwise repeat
    // the same warning fifty times.
    $logger = recordingLogger();
    $reader = modelSchemaReader(new ComponentSchemaRegistry(), logger: $logger);

    $reader->propertyFor(ConflictedMetadataArticle::class, 'id');
    $second = $reader->propertyFor(ConflictedMetadataArticle::class, 'id');

    // The second lookup must still be pruned: quieting the log must not quieten the fix with it.
    expect(discardedKeywordWarnings($logger))->toHaveCount(1)
        ->and(is_undefined($second?->minimum))->toBeTrue();
});

// region Value-level keywords: enum and format against the resolved type

it('drops an enum whose members cannot match the resolved type', function (): void {
    // A migration enum() column contributes members but no type of its own, so a contradicting tag
    // leaves string members beside an integer type: an unsatisfiable schema, not merely an inert one.
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'status');

    expect($property->type)->toBe('integer')
        ->and(is_undefined($property->enum))->toBeTrue();
});

it('drops an enum whose string members cannot match an array-typed property', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'flags');

    expect($property->type)->toBe('array')
        ->and(is_undefined($property->enum))->toBeTrue();
});

it('drops a date format the resolved type cannot carry', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'published_on');

    expect($property->type)->toBe('integer')
        ->and(is_undefined($property->format))->toBeTrue();
});

it('drops a uuid format the resolved type cannot carry', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'reference');

    expect($property->type)->toBe('integer')
        ->and(is_undefined($property->format))->toBeTrue();
});

it('keeps an enum whose members match the resolved type', function (): void {
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'state');

    expect($property->type)->toBe('string')
        ->and($property->enum)->toBe(['on', 'off']);
});

it('keeps an enum while one union member matches its members', function (): void {
    // A nullable column reaches the pass already widened to ['string', 'null'], so an every-member
    // rule would strip the enum from every nullable enum column in an app.
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'tier');

    expect($property->type)->toBe(['string', 'null'])
        ->and($property->enum)->toBe(['free', 'paid']);
});

it('keeps a uuid format the resolved type does carry', function (): void {
    // The one format in the table with no other positive assertion in this suite: a typo in its row
    // would prune silently everywhere else.
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'token');

    expect($property->type)->toBe('string')
        ->and($property->format)->toBe('uuid');
});

it('keeps an enum on a property with no resolved type', function (): void {
    // An untyped property short-circuits the whole pass before either value-level check runs, so
    // this covers that early return rather than the enum rule itself.
    $property = modelProperty(buildModelSchema(ConflictedMetadataArticle::class), 'mode');

    expect(is_undefined($property->type))->toBeTrue()
        ->and($property->enum)->toBe(['auto', 'manual']);
});

it('keeps an enum when only some of its members match the resolved type', function (): void {
    // A migration enum() column contributes string members only, so a mixed set is built directly.
    // It still narrows the instance, so one matching member keeps the whole enum: members are never
    // filtered individually.
    $property = new OA\Property(['property' => 'label', 'type' => 'string', 'enum' => ['a', 5]]);

    dropInapplicableKeywordsOn($property);

    expect($property->enum)->toBe(['a', 5]);
});

it('matches an integer enum member against an integer-typed property', function (): void {
    // The numeric member row names both `integer` and `number`, because JSON Schema's `integer` is a
    // subset of `number`. Naming only `number` would read this legitimate pairing as a conflict.
    $property = new OA\Property(['property' => 'weight', 'type' => 'integer', 'enum' => [1, 2]]);

    dropInapplicableKeywordsOn($property);

    expect($property->enum)->toBe([1, 2]);
});

it('keeps a format the applicability table does not list', function (): void {
    // No migration head emits a format outside the table, so this shape is only reachable by
    // building the property directly. An unlisted format is custom or newly registered and its
    // applicable types are unknown here, so it must never be pruned. This is also the guard for a
    // future numeric row: `int64` applies to `integer` even though the OpenAPI format registry
    // spells its JSON data type `number`, so whoever adds the row must list both names.
    $property = new OA\Property(['property' => 'size', 'type' => 'integer', 'format' => 'int64']);

    dropInapplicableKeywordsOn($property);

    expect($property->type)->toBe('integer')
        ->and($property->format)->toBe('int64');
});

// endregion
