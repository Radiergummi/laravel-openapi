<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use DateTimeImmutable;
use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\PublicPropertyTypeReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;
use Radiergummi\OpenApi\Tests\Fixtures\UnitFixtureEnum;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function Radiergummi\OpenApi\is_undefined;

uses()->group('openapi');

interface IntersectionLeft {}

interface IntersectionRight {}

class IntersectionFixture implements IntersectionLeft, IntersectionRight {}

/**
 * Exercises every type shape and refusal gate of the public-property reader.
 *
 * Reflected over, never instantiated: properties carry the types under test, not real values.
 */
class PublicPropertyReaderFixture
{
    /** @phpstan-ignore missingType.property (an intentionally untyped property is a refusal case) */
    public $untyped;

    public static string $shared = '';

    public function __construct(
        public string $name = '',
        public int $count = 0,
        public float $ratio = 0.0,
        public bool $active = false,
        public ?string $note = null,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public ArticleStatus $status = ArticleStatus::Draft,
        public int|string $mixedKey = 0,
        public IntersectionLeft&IntersectionRight $intersection = new IntersectionFixture(),
        public UnitFixtureEnum $kind = UnitFixtureEnum::Alpha,
        public array $promotedTags = [],
        protected string $secret = '',
    ) {}
}

class PublicPropertyLeafDto
{
    public function __construct(public string $label = '') {}
}

/**
 * Carries array/collection/`@var`-refined properties on non-promoted public members, which (unlike
 * promoted constructor parameters) expose a property docblock to reflection.
 */
class PublicPropertyDocblockFixture
{
    /**
     * A bare native array carries no element shape: maps to an unconstrained items schema.
     *
     * @phpstan-ignore missingType.iterableValue (a bare native array is a case under test)
     */
    public array $tags = [];

    /** @var list<int> */
    public array $ids = [];

    /** @var array<string, int> */
    public array $counts = [];

    /** @var array{id: int, name: string} */
    public array $meta = ['id' => 0, 'name' => ''];

    /** @var list<PublicPropertyLeafDto> */
    public array $children = [];

    /**
     * A native `string` refined by an unmappable union `@var`: the reader must keep the native type.
     *
     * @var int|string
     *
     * @phpstan-ignore property.phpDocType (an intentionally unmappable @var is the fallback case)
     */
    public string $refinedButUnion = '';

    /**
     * An unresolvable `@var` class: the reader must fall back to the native `string`.
     *
     * @var Nonexistent
     *
     * @phpstan-ignore property.phpDocType (non-existent @var is the fallback case), class.notFound (non-existent @var is the fallback case)
     */
    public string $unresolvable = '';
}

function publicPropertyTypeReader(): PublicPropertyTypeReader
{
    $registry = new ComponentSchemaRegistry();

    return new PublicPropertyTypeReader(
        jsonSchemaFromType: new JsonSchemaFromType(new NullLogger(), $registry),
        typeResolver: TypeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        typeNodeResolver: TypeNodeResolver::create(),
    );
}

it('types scalar public properties', function (string $property, string $type): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyReaderFixture::class, $property);

    expect($schema)->not->toBeNull()
        ->and($schema->property)->toBe($property)
        ->and($schema->type)->toBe($type);
})->with([
    'string' => ['name', 'string'],
    'int' => ['count', 'integer'],
    'float' => ['ratio', 'number'],
    'bool' => ['active', 'boolean'],
]);

it('types a nullable scalar as nullable', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyReaderFixture::class, 'note');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe(['string', 'null']);
});

it('types a date-time property with the date-time format', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyReaderFixture::class, 'createdAt');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('string')
        ->and($schema->format)->toBe('date-time');
});

it('keeps a top-level format object as its format even when a leaf callback is present', function (): void {
    // A DTO consumer always passes a leaf callback; a format leaf (DateTime/UUID/…) must still map to
    // its format, never route through the callback into a `$ref`.
    $schema = publicPropertyTypeReader()->propertyFor(
        PublicPropertyReaderFixture::class,
        'createdAt',
        static fn(string $class): OA\Schema => new OA\Schema(['ref' => '#/components/schemas/ShouldNotAppear']),
    );

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('string')
        ->and($schema->format)->toBe('date-time')
        ->and(is_undefined($schema->ref))->toBeTrue();
});

it('refers a backed enum property to its component', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyReaderFixture::class, 'status');

    expect($schema)->not->toBeNull()
        ->and($schema->ref)->toBe('#/components/schemas/ArticleStatus');
});

it('refuses to type properties that cannot be modelled without guessing', function (string $property): void {
    expect(publicPropertyTypeReader()->propertyFor(PublicPropertyReaderFixture::class, $property))->toBeNull();
})->with([
    'union' => ['mixedKey'],
    'intersection' => ['intersection'],
    // A unit (non-backed) enum has no JSON-primitive representation; refusing keeps the
    // value-object path from emitting JsonSchemaFromType's apology-string placeholder.
    'unit enum' => ['kind'],
    'untyped' => ['untyped'],
    'non-public' => ['secret'],
    'static' => ['shared'],
    'absent' => ['doesNotExist'],
]);

it('refuses a non-existent class', function (): void {
    // @phpstan-ignore argument.type (a deliberately non-existent class is a refusal case)
    expect(publicPropertyTypeReader()->propertyFor('Radiergummi\\OpenApi\\Tests\\Nope\\Missing', 'name'))
        ->toBeNull();
});

it('types a non-promoted bare native array property as an unconstrained array', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyDocblockFixture::class, 'tags');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('array')
        ->and(is_undefined($schema->items->type))->toBeTrue();
});

it('types a promoted native array property as an unconstrained array', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyReaderFixture::class, 'promotedTags');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('array')
        ->and(is_undefined($schema->items->type))->toBeTrue();
});

it('types a `@var list<int>` property as an array of integers', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyDocblockFixture::class, 'ids');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('array')
        ->and($schema->items->type)->toBe('integer');
});

it('types a `@var array<string, int>` property as a map', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyDocblockFixture::class, 'counts');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('object')
        ->and($schema->additionalProperties->type)->toBe('integer');
});

it('types a `@var array{…}` property as an object with required fields', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyDocblockFixture::class, 'meta');

    $types = [];

    foreach ($schema->properties as $property) {
        $types[$property->property] = $property->type;
    }

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('object')
        ->and($types)->toBe(['id' => 'integer', 'name' => 'string'])
        ->and($schema->required)->toEqualCanonicalizing(['id', 'name']);
});

it('refs each element of a `@var list<Dto>` property when a leaf callback is supplied', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(
        PublicPropertyDocblockFixture::class,
        'children',
        static fn(string $class): OA\Schema => new OA\Schema(['ref' => '#/components/schemas/Leaf']),
    );

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('array')
        ->and($schema->items->ref)->toBe('#/components/schemas/Leaf');
});

it('falls back to the native array for a `@var list<Dto>` property when no callback is supplied', function (): void {
    // Without a callback the object leaf is unmappable, so the unmappable `@var` is skipped and the
    // native `array` types the field, an unconstrained array rather than a refusal.
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyDocblockFixture::class, 'children');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('array')
        ->and(is_undefined($schema->items->type))->toBeTrue();
});

it('keeps the native type when a `@var` refines it to an unmappable union', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(
        PublicPropertyDocblockFixture::class,
        'refinedButUnion',
    );

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('string')
        ->and(is_undefined($schema->oneOf))->toBeTrue();
});

it('falls back to the native type when a `@var` names an unresolvable class', function (): void {
    $schema = publicPropertyTypeReader()->propertyFor(PublicPropertyDocblockFixture::class, 'unresolvable');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('string');
});
