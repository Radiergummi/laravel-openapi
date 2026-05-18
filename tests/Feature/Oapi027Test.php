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
use Psr\Log\NullLogger;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\BaseEventResource;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\BaseShapeData;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\CircleData;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\MessageEventResource;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\RectangleData;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\StatusEventResource;

uses()->group('openapi');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function oapi027DataRegistry(): ComponentSchemaRegistry
{
    $registry = new ComponentSchemaRegistry();
    $builder = new SchemaFromDataClass(
        schemaFromType: new JsonSchemaFromType(new NullLogger()),
        typeResolver: TypeResolver::create(),
        registry: $registry,
        payloadBuilder: new DataSyntheticPayloadBuilder(app(DataConfig::class)),
        rulesToSchema: new ValidationRulesToSchema(),
        dataConfig: app(DataConfig::class),
        logger: new NullLogger(),
    );

    $builder->build(BaseShapeData::class);

    return $registry;
}

function oapi027ResourceRegistry(): ComponentSchemaRegistry
{
    $registry = new ComponentSchemaRegistry();
    $extractor = new SchemaFromApiResource($registry);
    $extractor->build(BaseEventResource::class);

    return $registry;
}

/**
 * Decode a registered schema by class name into an associative array.
 *
 * @param class-string $class
 *
 * @return array<string, mixed>
 */
function oapi027DecodeSchema(ComponentSchemaRegistry $registry, string $class): array
{
    $key = $registry->keyFor($class);
    expect($key)->not->toBeNull("Schema for {$class} was not registered");

    foreach ($registry->all() as $schema) {
        if ($schema->schema === $key) {
            return json_decode($schema->toJson(), associative: true, flags: JSON_THROW_ON_ERROR);
        }
    }

    return [];
}

// ---------------------------------------------------------------------------
// Data class (SchemaFromDataClass) tests
// ---------------------------------------------------------------------------

it('OAPI-027: base Data class with #[Discriminator] emits oneOf instead of a flat object', function (): void {
    $registry = oapi027DataRegistry();
    $decoded  = oapi027DecodeSchema($registry, BaseShapeData::class);

    expect($decoded)->toHaveKey('oneOf')
        ->and($decoded)->not->toHaveKey('properties')
        ->and($decoded)->not->toHaveKey('type');
});

it('OAPI-027: base Data class oneOf lists $ref entries for each variant', function (): void {
    $registry = oapi027DataRegistry();
    $decoded  = oapi027DecodeSchema($registry, BaseShapeData::class);

    $refs = array_column($decoded['oneOf'], '$ref');

    expect($refs)->toContain('#/components/schemas/CircleData')
        ->and($refs)->toContain('#/components/schemas/RectangleData');
});

it('OAPI-027: base Data class emits discriminator.propertyName', function (): void {
    $registry = oapi027DataRegistry();
    $decoded  = oapi027DecodeSchema($registry, BaseShapeData::class);

    expect($decoded)->toHaveKey('discriminator')
        ->and($decoded['discriminator'])->toHaveKey('propertyName')
        ->and($decoded['discriminator']['propertyName'])->toBe('type');
});

it('OAPI-027: base Data class discriminator.mapping points to $ref strings', function (): void {
    $registry = oapi027DataRegistry();
    $decoded  = oapi027DecodeSchema($registry, BaseShapeData::class);

    $mapping = $decoded['discriminator']['mapping'] ?? [];

    expect($mapping)->toHaveKey('circle')
        ->and($mapping['circle'])->toBe('#/components/schemas/CircleData')
        ->and($mapping)->toHaveKey('rectangle')
        ->and($mapping['rectangle'])->toBe('#/components/schemas/RectangleData');
});

it('OAPI-027: variant Data classes are registered as their own component schemas', function (): void {
    $registry = oapi027DataRegistry();

    expect($registry->isRegisteredOrReserved(CircleData::class))->toBeTrue()
        ->and($registry->isRegisteredOrReserved(RectangleData::class))->toBeTrue();
});

it('OAPI-027: variant Data class schemas are flat objects with their own properties', function (): void {
    $registry = oapi027DataRegistry();

    $circle    = oapi027DecodeSchema($registry, CircleData::class);
    $rectangle = oapi027DecodeSchema($registry, RectangleData::class);

    expect($circle)->toHaveKey('properties')
        ->and($circle['properties'])->toHaveKey('radius')
        ->and($rectangle)->toHaveKey('properties')
        ->and($rectangle['properties'])->toHaveKey('width')
        ->and($rectangle['properties'])->toHaveKey('height');
});

// ---------------------------------------------------------------------------
// ApiResource (SchemaFromApiResource) tests
// ---------------------------------------------------------------------------

it('OAPI-027: base ApiResource with #[Discriminator] emits oneOf instead of a flat JSON:API object', function (): void {
    $registry = oapi027ResourceRegistry();
    $decoded  = oapi027DecodeSchema($registry, BaseEventResource::class);

    expect($decoded)->toHaveKey('oneOf')
        ->and($decoded)->not->toHaveKey('properties');
});

it('OAPI-027: base ApiResource oneOf lists $ref entries for each variant', function (): void {
    $registry = oapi027ResourceRegistry();
    $decoded  = oapi027DecodeSchema($registry, BaseEventResource::class);

    $refs = array_column($decoded['oneOf'], '$ref');

    expect($refs)->toContain('#/components/schemas/MessageEventResource')
        ->and($refs)->toContain('#/components/schemas/StatusEventResource');
});

it('OAPI-027: base ApiResource emits discriminator.propertyName', function (): void {
    $registry = oapi027ResourceRegistry();
    $decoded  = oapi027DecodeSchema($registry, BaseEventResource::class);

    expect($decoded)->toHaveKey('discriminator')
        ->and($decoded['discriminator']['propertyName'])->toBe('type');
});

it('OAPI-027: base ApiResource discriminator.mapping points to $ref strings', function (): void {
    $registry = oapi027ResourceRegistry();
    $decoded  = oapi027DecodeSchema($registry, BaseEventResource::class);

    $mapping = $decoded['discriminator']['mapping'] ?? [];

    expect($mapping)->toHaveKey('message')
        ->and($mapping['message'])->toBe('#/components/schemas/MessageEventResource')
        ->and($mapping)->toHaveKey('status')
        ->and($mapping['status'])->toBe('#/components/schemas/StatusEventResource');
});

it('OAPI-027: variant ApiResource schemas are registered as independent component schemas', function (): void {
    $registry = oapi027ResourceRegistry();

    expect($registry->isRegisteredOrReserved(MessageEventResource::class))->toBeTrue()
        ->and($registry->isRegisteredOrReserved(StatusEventResource::class))->toBeTrue();
});
