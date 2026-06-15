<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\DataSyntheticPayloadBuilder;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\SchemaFromDataClass;
use Radiergummi\OpenApi\Support\Extraction\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Tests\Fixtures\DeprecatedAttributeFieldFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\DeprecatedFieldFixtureData;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

// TODO(tracker §5): these tests call SchemaFromDataClass directly; rewrite to drive
// openapi:generate against a route returning the Data class and assert on the spec.

uses()->group('openapi');

/**
 * @return array<string, OA\Property>
 */
function deprecatedPropertiesByName(OA\Schema $schema): array
{
    if (!is_array($schema->properties)) {
        return [];
    }

    $out = [];

    foreach ($schema->properties as $property) {
        if ($property instanceof OA\Property) {
            $out[$property->property] = $property;
        }
    }

    return $out;
}

function makeDeprecatedSchemaBuilder(?ComponentSchemaRegistry $registry = null): SchemaFromDataClass
{
    $registry ??= new ComponentSchemaRegistry();

    return new SchemaFromDataClass(
        schemaFromType: new JsonSchemaFromType(new NullLogger(), new ComponentSchemaRegistry()),
        typeResolver: TypeResolver::create(),
        registry: $registry,
        payloadBuilder: new DataSyntheticPayloadBuilder(app(DataConfig::class)),
        rulesToSchema: new ValidationRulesToSchema(),
        dataConfig: app(DataConfig::class),
        logger: new NullLogger(),
        synthesiser: new FakerExampleSynthesiser(enabled: false),
        explicitSchema: new ExplicitClassSchema(new NullLogger()),
    );
}

it('OAPI-031: non-deprecated Data property does not have deprecated: true', function (): void {
    $registry = new ComponentSchemaRegistry();
    makeDeprecatedSchemaBuilder($registry)->build(DeprecatedFieldFixtureData::class);

    $props = deprecatedPropertiesByName($registry->all()[0]);

    expect($props)->toHaveKey('active');
    expect($props['active']->deprecated)->not->toBeTrue();
});

it('OAPI-031: @deprecated PHPDoc on a Data property emits deprecated: true on the property schema', function (): void {
    $registry = new ComponentSchemaRegistry();
    makeDeprecatedSchemaBuilder($registry)->build(DeprecatedFieldFixtureData::class);

    $props = deprecatedPropertiesByName($registry->all()[0]);

    expect($props)->toHaveKey('legacy');
    expect($props['legacy']->deprecated)->toBeTrue();
});

it('OAPI-043: #[Deprecated] on a Data property emits deprecated: true on the property schema', function (): void {
    $registry = new ComponentSchemaRegistry();
    makeDeprecatedSchemaBuilder($registry)->build(DeprecatedAttributeFieldFixtureData::class);

    $props = deprecatedPropertiesByName($registry->all()[0]);

    expect($props)->toHaveKey('legacy');
    expect($props['legacy']->deprecated)->toBeTrue();
});

it('OAPI-043: non-deprecated Data property does not have deprecated: true', function (): void {
    $registry = new ComponentSchemaRegistry();
    makeDeprecatedSchemaBuilder($registry)->build(DeprecatedAttributeFieldFixtureData::class);

    $props = deprecatedPropertiesByName($registry->all()[0]);

    expect($props)->toHaveKey('active');
    expect($props['active']->deprecated)->not->toBeTrue();
});
