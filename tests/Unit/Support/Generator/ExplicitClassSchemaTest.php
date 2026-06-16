<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Attributes\RawSchema;
use Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema;
use Radiergummi\OpenApi\Tests\Fixtures\AddressFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\RawSchema\RawSchemaData;
use Radiergummi\OpenApi\Tests\Fixtures\RawSchema\RawSchemaResource;
use Radiergummi\OpenApi\Tests\Fixtures\RawSchema\RawSchemaUnsupportedKeywordData;

uses()->group('openapi');

function explicitClassSchema(): ExplicitClassSchema
{
    return new ExplicitClassSchema(new NullLogger());
}

it('reads the #[RawSchema] attribute when present', function (): void {
    $attribute = explicitClassSchema()->read(new ReflectionClass(RawSchemaData::class));

    expect($attribute)
        ->toBeInstanceOf(RawSchema::class)
        ->and($attribute->schema['properties'])->toHaveKey('kind');
});

it('returns null when the class has no #[RawSchema]', function (): void {
    expect(explicitClassSchema()->read(new ReflectionClass(AddressFixtureData::class)))->toBeNull();
});

it('builds a valid object schema from the literal definition', function (): void {
    $attribute = new RawSchema([
        'type' => 'object',
        'required' => ['a'],
        'properties' => [
            'a' => ['type' => 'string'],
        ],
    ]);

    $schema = explicitClassSchema()->toSchema($attribute, new ReflectionClass(RawSchemaData::class));

    expect($schema->type)
        ->toBe('object')
        ->and($schema->required)->toBe(['a'])
        ->and($schema->properties[0]->property)->toBe('a')
        ->and($schema->validate())->toBeTrue();
});

it('preserves composition (oneOf) and const keywords and validates', function (): void {
    $attribute = new RawSchema([
        'oneOf' => [
            ['const' => 'a'],
            ['const' => 'b'],
        ],
    ]);

    $schema = explicitClassSchema()->toSchema($attribute, new ReflectionClass(RawSchemaResource::class));

    expect($schema->oneOf)
        ->toHaveCount(2)
        ->and($schema->validate())->toBeTrue();
});

it('drops an unsupported keyword and still validates', function (): void {
    $attribute = (new ReflectionClass(RawSchemaUnsupportedKeywordData::class))
        ->getAttributes(RawSchema::class)[0]
        ->newInstance();

    $schema = explicitClassSchema()->toSchema($attribute, new ReflectionClass(RawSchemaUnsupportedKeywordData::class));

    // The `if` keyword is gone, and the surviving body validates on both swagger-php majors.
    expect($schema->validate())->toBeTrue();
});

it('reports unsupported keywords without touching the supported set', function (): void {
    $unsupported = ExplicitClassSchema::unsupportedKeywords([
        'type' => 'object',
        'oneOf' => [],
        'x-vendor' => true,
        'if' => [],
        'dependentRequired' => [],
        'dependencies' => [],
    ]);

    expect($unsupported)->toBe(['if', 'dependentRequired', 'dependencies']);
});
