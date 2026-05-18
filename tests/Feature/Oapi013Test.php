<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Plugins\JsonApi\SchemaFromApiResource;
use Radiergummi\OpenApi\Tests\Fixtures\ConditionalFieldFixtureResource;

uses()->group('openapi');

/**
 * Decode the first registered schema and return the raw decoded array.
 *
 * @return array<string, mixed>
 */
function decodeFirstSchema(string $resourceClass): array
{
    $registry = new ComponentSchemaRegistry();
    $extractor = new SchemaFromApiResource($registry);
    $extractor->build($resourceClass);

    $schemas = $registry->all();

    return json_decode($schemas[0]->toJson(), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Navigate the swagger-php serialised schema to the `attributes` sub-schema.
 *
 * swagger-php wraps OA\Property's child schema in a `schema` key, so the path
 * is `properties.attributes.schema` (not `properties.attributes` directly).
 *
 * @return array{properties?: array<string, mixed>, required?: list<string>}
 */
function oapi013AttributesSchema(string $resourceClass): array
{
    $decoded = decodeFirstSchema($resourceClass);

    return $decoded['properties']['attributes']['schema']
        ?? $decoded['properties']['attributes']
        ?? [];
}

/**
 * Navigate the swagger-php serialised schema to the `meta` sub-schema.
 *
 * @return array{properties?: array<string, mixed>}
 */
function oapi013MetaSchema(string $resourceClass): array
{
    $decoded = decodeFirstSchema($resourceClass);

    return $decoded['properties']['meta']['schema']
        ?? $decoded['properties']['meta']
        ?? [];
}

it('OAPI-013: non-conditional field appears in attributes.required', function (): void {
    $attributes = oapi013AttributesSchema(ConditionalFieldFixtureResource::class);

    expect($attributes)->toHaveKey('required')
        ->and($attributes['required'])->toContain('name');
});

it('OAPI-013: conditional field (#[ResponseField(conditional: true)]) is in attributes.properties but NOT in required', function (): void {
    $attributes = oapi013AttributesSchema(ConditionalFieldFixtureResource::class);

    // 'owner' must be in properties (the field is documented).
    expect($attributes['properties'])->toHaveKey('owner');

    // 'owner' must NOT be in required (it is conditional).
    $required = $attributes['required'] ?? [];
    expect($required)->not->toContain('owner');
});

it('OAPI-013: array-valued META_PERMISSIONS produces a nested object schema with boolean properties', function (): void {
    $meta = oapi013MetaSchema(ConditionalFieldFixtureResource::class);

    // The meta object must have a 'permissions' property.
    expect($meta)->toHaveKey('properties')
        ->and($meta['properties'])->toHaveKey('permissions');

    $permissionsRaw = $meta['properties']['permissions'];

    // swagger-php wraps OA\Property's child schema under a 'schema' key.
    $permissions = $permissionsRaw['schema'] ?? $permissionsRaw;

    // It must be a nested object with the three permission keys as boolean properties.
    expect($permissions)->toHaveKey('properties')
        ->and($permissions['properties'])->toHaveKey('addCollaborators')
        ->and($permissions['properties'])->toHaveKey('read')
        ->and($permissions['properties'])->toHaveKey('write')
        ->and($permissions['properties']['addCollaborators']['type'])->toBe('boolean')
        ->and($permissions['properties']['read']['type'])->toBe('boolean')
        ->and($permissions['properties']['write']['type'])->toBe('boolean');
});
