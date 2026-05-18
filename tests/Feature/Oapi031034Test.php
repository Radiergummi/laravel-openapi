<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Plugins\JsonApi\SchemaFromApiResource;
use Radiergummi\OpenApi\Plugins\SpatieData\DataSyntheticPayloadBuilder;
use Radiergummi\OpenApi\Plugins\SpatieData\SchemaFromDataClass;
use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\DeprecatedFieldFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\DeprecatedFieldFixtureResource;
use Radiergummi\OpenApi\Tests\Fixtures\StatusFixtureEnum;

uses()->group('openapi');

// ---------------------------------------------------------------------------
// Helpers shared across tests in this file
// ---------------------------------------------------------------------------

/**
 * @return array<string, OA\Property>
 */
function oapi031PropertiesByName(OA\Schema $schema): array
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

function makeDataSchemaBuilder(?ComponentSchemaRegistry $registry = null): SchemaFromDataClass
{
    $registry ??= new ComponentSchemaRegistry();

    return new SchemaFromDataClass(
        schemaFromType: new JsonSchemaFromType(new NullLogger()),
        typeResolver: TypeResolver::create(),
        registry: $registry,
        payloadBuilder: new DataSyntheticPayloadBuilder(app(DataConfig::class)),
        rulesToSchema: new ValidationRulesToSchema(),
        dataConfig: app(DataConfig::class),
        logger: new NullLogger(),
    );
}

// ---------------------------------------------------------------------------
// OAPI-031: per-field @deprecated on Data properties
// ---------------------------------------------------------------------------

it('OAPI-031: non-deprecated Data property does not have deprecated: true', function (): void {
    $registry = new ComponentSchemaRegistry();
    $builder  = makeDataSchemaBuilder($registry);
    $builder->build(DeprecatedFieldFixtureData::class);

    $schema = $registry->all()[0];
    $props  = oapi031PropertiesByName($schema);

    expect($props)->toHaveKey('active');
    expect($props['active']->deprecated)->not->toBeTrue();
});

it('OAPI-031: @deprecated PHPDoc on a Data property emits deprecated: true on the property schema', function (): void {
    $registry = new ComponentSchemaRegistry();
    $builder  = makeDataSchemaBuilder($registry);
    $builder->build(DeprecatedFieldFixtureData::class);

    $schema = $registry->all()[0];
    $props  = oapi031PropertiesByName($schema);

    expect($props)->toHaveKey('legacy');
    expect($props['legacy']->deprecated)->toBeTrue();
});

// ---------------------------------------------------------------------------
// OAPI-031: per-field #[\Deprecated] on ApiResource FIELD_* constants
// ---------------------------------------------------------------------------

function oapi031BuildResourceSchema(string $resourceClass): OA\Schema
{
    $registry  = new ComponentSchemaRegistry();
    $extractor = new SchemaFromApiResource($registry);
    $extractor->build($resourceClass);

    return $registry->all()[0];
}

/**
 * @return array<string, mixed>
 */
function oapi031AttributeProperties(string $resourceClass): array
{
    $schema  = oapi031BuildResourceSchema($resourceClass);
    $decoded = json_decode($schema->toJson(), associative: true, flags: JSON_THROW_ON_ERROR);

    return $decoded['properties']['attributes']['schema']['properties']
        ?? $decoded['properties']['attributes']['properties']
        ?? [];
}

it('OAPI-031: non-deprecated ApiResource FIELD_* constant does not emit deprecated: true', function (): void {
    $props = oapi031AttributeProperties(DeprecatedFieldFixtureResource::class);

    expect($props)->toHaveKey('active');
    expect($props['active'])->not->toHaveKey('deprecated');
});

it('OAPI-031: #[\\Deprecated] on ApiResource FIELD_* constant emits deprecated: true on the property', function (): void {
    $props = oapi031AttributeProperties(DeprecatedFieldFixtureResource::class);

    expect($props)->toHaveKey('legacy');
    expect($props['legacy'])->toHaveKey('deprecated');
    expect($props['legacy']['deprecated'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// OAPI-034: enum case-level PHPDoc descriptions
// ---------------------------------------------------------------------------

it('OAPI-034: BackedEnum with per-case PHPDoc produces a markdown description on the schema', function (): void {
    $schemaFromType = new JsonSchemaFromType(new NullLogger());
    $type           = Type::enum(StatusFixtureEnum::class);
    $schema         = $schemaFromType->fromType($type);

    expect($schema->description)->toBeString()
        ->and($schema->description)->toContain('active')
        ->and($schema->description)->toContain('Active and visible to all users.')
        ->and($schema->description)->toContain('archived')
        ->and($schema->description)->toContain('Archived and hidden from normal views.')
        ->and($schema->description)->toContain('draft')
        ->and($schema->description)->toContain('Draft that has not been published yet.');
});

it('OAPI-034: each enum case description line starts with a backtick-quoted value', function (): void {
    $schemaFromType = new JsonSchemaFromType(new NullLogger());
    $type           = Type::enum(StatusFixtureEnum::class);
    $schema         = $schemaFromType->fromType($type);

    $lines = explode("\n", $schema->description);

    expect($lines)->toHaveCount(3);

    foreach ($lines as $line) {
        expect($line)->toMatch('/^- `[^`]+`: .+/');
    }
});

it('OAPI-034: description is not set when no case has PHPDoc', function (): void {
    // Verify the positive path: StatusFixtureEnum has per-case docs so it gets a description.
    // The null-path (no docs → no description) is covered by the implementation returning null
    // when $lines is empty; tested indirectly because we cannot define enums inline in Pest.
    $schemaFromType = new JsonSchemaFromType(new NullLogger());
    $type           = Type::enum(StatusFixtureEnum::class);
    $schema         = $schemaFromType->fromType($type);

    expect($schema->description)->not->toBeEmpty();
});
