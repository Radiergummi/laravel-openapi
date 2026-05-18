<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Plugins\SpatieData\DataSyntheticPayloadBuilder;
use Radiergummi\OpenApi\Plugins\SpatieData\SchemaFromDataClass;
use Radiergummi\OpenApi\Tests\Fixtures\Alpha\SelfRefData as AlphaSelfRefData;
use Radiergummi\OpenApi\Tests\Fixtures\Beta\SelfRefData as BetaSelfRefData;
use Radiergummi\OpenApi\Tests\Fixtures\MapInputNameFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\PropertyFixtureData;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi', 'plugin:spatie-data');

beforeEach(function (): void {
    $this->registry = new ComponentSchemaRegistry();
    $this->builder  = new SchemaFromDataClass(
        schemaFromType: new JsonSchemaFromType(new NullLogger()),
        typeResolver: TypeResolver::create(),
        registry: $this->registry,
        payloadBuilder: new DataSyntheticPayloadBuilder(app(DataConfig::class)),
        rulesToSchema: new ValidationRulesToSchema(),
        dataConfig: app(DataConfig::class),
        logger: new NullLogger(),
    );
});

/**
 * @return array<string, OA\Property>
 */
function propertiesByName(OA\Schema $schema): array
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

it('applies the OpenApi\\Property attribute fields onto the property schema', function (): void {
    $this->builder->build(PropertyFixtureData::class);

    $schema = $this->registry->all()[0] ?? null;
    expect($schema)->toBeInstanceOf(OA\Schema::class);

    $props = propertiesByName($schema);

    expect($props)->toHaveKeys(['name', 'callbackUrl', 'limit']);

    expect($props['name']->description)->toBe('Display name shown in lists.')
        ->and($props['name']->example)->toBe('Aerospace Q1')
        ->and($props['name']->maxLength)->toBe(250)
        ->and($props['name']->type)->toBe('string');

    expect($props['callbackUrl']->format)->toBe('uri')
        ->and($props['callbackUrl']->example)->toBe('https://hooks.example.com/projects')
        ->and($props['callbackUrl']->type)->toBe(['string', 'null']);
});

it('leaves properties without the attribute untouched', function (): void {
    $this->builder->build(PropertyFixtureData::class);

    $schema = $this->registry->all()[0];
    $props = propertiesByName($schema);

    $undefined = Generator::UNDEFINED;

    expect($props['limit']->type)->toBe('integer')
        ->and($props['limit']->description)->toBe($undefined)
        ->and($props['limit']->example)->toBe($undefined);
});

// ---------------------------------------------------------------------------
// OAPI-001: #[MapInputName] resolution
// ---------------------------------------------------------------------------

it('uses the literal wire name from #[MapInputName] for the schema property key', function (): void {
    $this->builder->build(MapInputNameFixtureData::class);

    $schema = $this->registry->all()[0];
    $props  = propertiesByName($schema);

    expect($props)->toHaveKey('literal_name')
        ->and($props)->not->toHaveKey('literalName');
});

it('applies a NameMapper class from #[MapInputName] (SnakeCaseMapper)', function (): void {
    $this->builder->build(MapInputNameFixtureData::class);

    $schema = $this->registry->all()[0];
    $props  = propertiesByName($schema);

    expect($props)->toHaveKey('mapper_name')
        ->and($props)->not->toHaveKey('mapperName');
});

it('leaves unmapped properties on their PHP name', function (): void {
    $this->builder->build(MapInputNameFixtureData::class);

    $schema = $this->registry->all()[0];
    $props  = propertiesByName($schema);

    expect($props)->toHaveKey('unmapped');
});

it('uses wire names in the required[] list (Optional union still drops the field)', function (): void {
    $this->builder->build(MapInputNameFixtureData::class);

    $schema   = $this->registry->all()[0];
    $required = is_array($schema->required) ? $schema->required : [];

    // literal_name + mapper_name + unmapped are required (no defaults, not Optional).
    // optional_literal is Optional|null so it must be omitted from required.
    expect($required)->toContain('literal_name')
        ->and($required)->toContain('mapper_name')
        ->and($required)->toContain('unmapped')
        ->and($required)->not->toContain('optional_literal')
        ->and($required)->not->toContain('literalName')
        ->and($required)->not->toContain('mapperName');
});

// ---------------------------------------------------------------------------
// OAPI-008: Cycle-guard $ref uses disambiguated key for same-basename classes
// ---------------------------------------------------------------------------

it('emits a $ref with the basename key for a self-referential class (OAPI-008)', function (): void {
    $this->builder->build(AlphaSelfRefData::class);

    $schemas = $this->registry->all();
    $keys    = array_map(static fn(OA\Schema $s): string => $s->schema, $schemas);

    // The Alpha class should be registered under its basename (no collision yet).
    expect($keys)->toContain('SelfRefData');

    // The child property must reference the correct component key via $ref.
    $schema = null;

    foreach ($schemas as $s) {
        if ($s->schema === 'SelfRefData') {
            $schema = $s;

            break;
        }
    }

    expect($schema)->not->toBeNull();
    $props = propertiesByName($schema);
    expect($props)->toHaveKey('child');

    // The child property is ?self — OAS 3.1 nullable wraps it in oneOf: [{$ref:...},{type:'null'}].
    // The $ref lives inside oneOf[0], not directly on the property.
    $childRef = $props['child']->oneOf[0]->ref;
    expect($childRef)->toContain('SelfRefData');
});

it('disambiguates same-basename classes from different namespaces (OAPI-008)', function (): void {
    // Register Alpha first so Beta gets a disambiguated key.
    $this->builder->build(AlphaSelfRefData::class);
    $this->builder->build(BetaSelfRefData::class);

    $schemas = $this->registry->all();
    $keys    = array_map(static fn(OA\Schema $s): string => $s->schema, $schemas);

    // Alpha gets the plain basename; Beta gets a disambiguated key.
    expect($keys)->toContain('SelfRefData');

    $disambiguated = array_values(array_filter($keys, static fn(string $k): bool => $k !== 'SelfRefData'));
    expect($disambiguated)->not->toBeEmpty();

    // The Beta self-ref property must point to the disambiguated key, not 'SelfRefData'.
    $betaSchema = null;

    foreach ($schemas as $s) {
        if ($s->schema !== 'SelfRefData') {
            $betaSchema = $s;

            break;
        }
    }

    expect($betaSchema)->not->toBeNull();
    $props = propertiesByName($betaSchema);
    expect($props)->toHaveKey('child');

    // OAS 3.1 nullable wraps the self-ref in oneOf: [{$ref:...},{type:'null'}].
    $childRef = $props['child']->oneOf[0]->ref;
    expect($childRef)->toContain($disambiguated[0]);
});
