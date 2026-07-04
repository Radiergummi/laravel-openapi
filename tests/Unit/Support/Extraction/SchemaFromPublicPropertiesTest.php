<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\PublicPropertyTypeReader;
use Radiergummi\OpenApi\Support\Extraction\SchemaFromPublicProperties;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;
use Radiergummi\OpenApi\Tests\Fixtures\UnitFixtureEnum;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function Radiergummi\OpenApi\is_undefined;

uses()->group('openapi');

class ScalarPropertiesDto
{
    public function __construct(
        public string $name = '',
        public int $age = 0,
        public ?string $nickname = null,
    ) {}
}

class NestedPropertiesDto
{
    public function __construct(
        public string $label = '',
        public ScalarPropertiesDto $owner = new ScalarPropertiesDto(),
    ) {}
}

class MixedPropertiesDto
{
    public ArticleStatus $status = ArticleStatus::Draft;

    // A bare array carries no element shape and a unit enum no JSON-primitive shape; the reader
    // refuses both, so the builder omits them rather than stubbing.
    /** @phpstan-ignore missingType.iterableValue (native array is the refusal case under test) */
    public array $tags = [];

    public UnitFixtureEnum $kind = UnitFixtureEnum::Alpha;
}

class NoUsablePropertiesService
{
    // A private property must be ignored, leaving no usable public property.
    /** @phpstan-ignore property.onlyWritten (a non-public property is the refusal case under test) */
    private string $secret = '';

    public function handle(): void {}
}

class SelfReferentialNode
{
    public function __construct(
        public string $value = '',
        public ?SelfReferentialNode $next = null,
    ) {}
}

final class PromotedReadonlyDto
{
    public function __construct(
        public readonly string $id = '',
        public readonly int $count = 0,
    ) {}
}

class DtoWithServiceProperty
{
    public function __construct(
        public string $label = '',
        public NoUsablePropertiesService $service = new NoUsablePropertiesService(),
    ) {}
}

class MutualLeftDto
{
    public function __construct(
        public string $id = '',
        public ?MutualRightDto $right = null,
    ) {}
}

class MutualRightDto
{
    public function __construct(
        public string $name = '',
        public ?MutualLeftDto $left = null,
    ) {}
}

/**
 * @return array{SchemaFromPublicProperties, ComponentSchemaRegistry}
 */
function schemaFromPublicProperties(): array
{
    $registry = new ComponentSchemaRegistry();
    $reader = new PublicPropertyTypeReader(
        jsonSchemaFromType: new JsonSchemaFromType(new NullLogger(), $registry),
        typeResolver: TypeResolver::create(),
    );

    return [new SchemaFromPublicProperties($registry, $reader), $registry];
}

it('builds an object schema from scalar public properties', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    $reference = $builder->buildRef(ScalarPropertiesDto::class);

    expect($reference)->toBe('#/components/schemas/ScalarPropertiesDto');

    $schema = $registry->schemaForKey('ScalarPropertiesDto');

    expect($schema)->not->toBeNull()
        ->and($schema->type)->toBe('object')
        ->and($schema->required)->toEqualCanonicalizing(['name', 'age']);

    $types = [];

    foreach ($schema->properties as $property) {
        $types[$property->property] = $property->type;
    }

    expect($types['name'])->toBe('string')
        ->and($types['age'])->toBe('integer')
        ->and($types['nickname'])->toBe(['string', 'null']);
});

it('refs a nested plain-object property to its own component', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    $builder->buildRef(NestedPropertiesDto::class);

    $schema = $registry->schemaForKey('NestedPropertiesDto');
    $owner = null;

    foreach ($schema->properties as $property) {
        if ($property->property === 'owner') {
            $owner = $property;
        }
    }

    expect($owner)->not->toBeNull()
        ->and($owner->ref)->toBe('#/components/schemas/ScalarPropertiesDto')
        ->and($registry->hasKey('ScalarPropertiesDto'))->toBeTrue();
});

it('refs a backed enum and omits properties the reader refuses', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    $builder->buildRef(MixedPropertiesDto::class);

    $schema = $registry->schemaForKey('MixedPropertiesDto');
    $byName = [];

    foreach ($schema->properties as $property) {
        $byName[$property->property] = $property;
    }

    // Only the backed enum types; the bare array and unit enum are refused (omitted, not stubbed).
    expect($byName)->toHaveKey('status')
        ->and($byName)->not->toHaveKey('tags')
        ->and($byName)->not->toHaveKey('kind')
        ->and($byName['status']->ref)->toBe('#/components/schemas/ArticleStatus');
});

it('degrades a class with no usable public property to null', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    expect($builder->buildRef(NoUsablePropertiesService::class))->toBeNull()
        ->and($registry->hasKey('NoUsablePropertiesService'))->toBeFalse();
});

it('degrades a non-existent class to null', function (): void {
    [$builder] = schemaFromPublicProperties();

    expect($builder->buildRef('Radiergummi\\OpenApi\\Tests\\Nope\\Missing'))->toBeNull();
});

it('degrades an enum passed directly to null', function (): void {
    [$builder] = schemaFromPublicProperties();

    expect($builder->buildRef(ArticleStatus::class))->toBeNull();
});

it('builds a self-referential class without infinite recursion', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    $reference = $builder->buildRef(SelfReferentialNode::class);

    expect($reference)->toBe('#/components/schemas/SelfReferentialNode');

    $schema = $registry->schemaForKey('SelfReferentialNode');
    $next = null;

    foreach ($schema->properties as $property) {
        if ($property->property === 'next') {
            $next = $property;
        }
    }

    // The recursive property is nullable, so it resolves to a oneOf of the self-$ref and null.
    expect($next)->not->toBeNull()
        ->and($next->oneOf[0]->ref)->toBe('#/components/schemas/SelfReferentialNode')
        // Nullable, so `next` is not required.
        ->and($schema->required)->toBe(['value']);
});

it('reads promoted readonly constructor properties', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    $builder->buildRef(PromotedReadonlyDto::class);

    $schema = $registry->schemaForKey('PromotedReadonlyDto');
    $types = [];

    foreach ($schema->properties as $property) {
        $types[$property->property] = $property->type;
    }

    expect($types['id'])->toBe('string')
        ->and($types['count'])->toBe('integer')
        ->and($schema->required)->toEqualCanonicalizing(['id', 'count']);
});

it('omits a nested property whose class has no usable properties, never stubbing it', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    $builder->buildRef(DtoWithServiceProperty::class);

    $schema = $registry->schemaForKey('DtoWithServiceProperty');
    $names = [];

    foreach ($schema->properties as $property) {
        $names[] = $property->property;
    }

    // The service property is omitted (its class yields no schema), never a stubbed component.
    expect($names)->toBe(['label'])
        ->and($registry->hasKey('NoUsablePropertiesService'))->toBeFalse();
});

it('builds mutually-referential classes without infinite recursion', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    $reference = $builder->buildRef(MutualLeftDto::class);

    expect($reference)->toBe('#/components/schemas/MutualLeftDto')
        ->and($registry->hasKey('MutualRightDto'))->toBeTrue();

    $rightProperty = null;

    foreach ($registry->schemaForKey('MutualLeftDto')->properties as $property) {
        if ($property->property === 'right') {
            $rightProperty = $property;
        }
    }

    $leftProperty = null;

    foreach ($registry->schemaForKey('MutualRightDto')->properties as $property) {
        if ($property->property === 'left') {
            $leftProperty = $property;
        }
    }

    // Each side references the other's component (nullable, so wrapped in oneOf with null).
    expect($rightProperty->oneOf[0]->ref)->toBe('#/components/schemas/MutualRightDto')
        ->and($leftProperty->oneOf[0]->ref)->toBe('#/components/schemas/MutualLeftDto');
});

it('is idempotent across repeated builds', function (): void {
    [$builder, $registry] = schemaFromPublicProperties();

    $first = $builder->buildRef(ScalarPropertiesDto::class);
    $second = $builder->buildRef(ScalarPropertiesDto::class);

    expect($first)->toBe($second)
        ->and(is_undefined($registry->schemaForKey('ScalarPropertiesDto')?->type))->toBeFalse();
});
