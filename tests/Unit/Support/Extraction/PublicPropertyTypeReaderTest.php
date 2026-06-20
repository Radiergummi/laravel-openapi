<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use DateTimeImmutable;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\PublicPropertyTypeReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

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
        protected string $secret = '',
    ) {}
}

function publicPropertyTypeReader(): PublicPropertyTypeReader
{
    $registry = new ComponentSchemaRegistry();

    return new PublicPropertyTypeReader(
        jsonSchemaFromType: new JsonSchemaFromType(new NullLogger(), $registry),
        typeResolver: TypeResolver::create(),
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
