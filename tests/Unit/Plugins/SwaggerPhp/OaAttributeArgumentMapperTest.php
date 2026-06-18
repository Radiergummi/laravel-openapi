<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OaAttributeArgumentMapper;

uses()->group('openapi', 'lint', 'fix');

/**
 * Builds an `OA\Property` from a property-value map, leaving every other field at the
 * `Generator::UNDEFINED` sentinel so the mapper sees only what the test set.
 *
 * @param array<string, mixed> $values
 */
function oaProperty(array $values): OA\Property
{
    return new OA\Property($values);
}

/**
 * @param array<string, mixed> $values
 */
function oaQueryParameter(array $values): OA\Parameter
{
    return new OA\Parameter(['in' => 'query', ...$values]);
}

it('maps scalar property keys to attribute arguments', function (): void {
    $arguments = new OaAttributeArgumentMapper()->mapProperty(oaProperty([
        'property' => 'name',
        'description' => 'The display name.',
        'type' => 'string',
        'format' => 'email',
        'minLength' => 3,
        'maxLength' => 255,
        'pattern' => '^.+$',
        'nullable' => true,
        'readOnly' => true,
        'deprecated' => true,
    ]));

    expect($arguments)->toEqualCanonicalizing([
        'description' => 'The display name.',
        'type' => 'string',
        'format' => 'email',
        'pattern' => '^.+$',
        'nullable' => true,
        'readOnly' => true,
        'deprecated' => true,
        'minLength' => 3,
        'maxLength' => 255,
    ]);
});

it('maps numeric range keys', function (): void {
    $arguments = new OaAttributeArgumentMapper()->mapProperty(oaProperty([
        'property' => 'count',
        'type' => 'integer',
        'minimum' => 0,
        'maximum' => 100,
        'multipleOf' => 5,
    ]));

    expect($arguments)->toEqualCanonicalizing([
        'type' => 'integer',
        'minimum' => 0,
        'maximum' => 100,
        'multipleOf' => 5,
    ]);
});

it('refuses a property carrying an array enum', function (): void {
    $property = oaProperty(['property' => 'status', 'type' => 'string']);
    $property->enum = ['active', 'archived'];

    expect(new OaAttributeArgumentMapper()->mapProperty($property))->toBeNull();
});

it('refuses a property carrying an array example', function (): void {
    $property = oaProperty(['property' => 'tags', 'type' => 'array']);
    $property->example = ['a', 'b'];

    expect(new OaAttributeArgumentMapper()->mapProperty($property))->toBeNull();
});

it('refuses a property carrying vendor extensions', function (): void {
    $property = oaProperty(['property' => 'name', 'type' => 'string']);
    $property->x = ['x-internal' => true];

    expect(new OaAttributeArgumentMapper()->mapProperty($property))->toBeNull();
});

it('refuses a property whose type is an array (union)', function (): void {
    $property = oaProperty(['property' => 'name']);
    $property->type = ['string', 'null'];

    expect(new OaAttributeArgumentMapper()->mapProperty($property))->toBeNull();
});

it('refuses a property carrying a nested items shape', function (): void {
    $property = oaProperty(['property' => 'tags', 'type' => 'array']);
    $property->items = new OA\Items(['type' => 'string']);

    expect(new OaAttributeArgumentMapper()->mapProperty($property))->toBeNull();
});

it('returns null for an empty property (only the member name set)', function (): void {
    expect(new OaAttributeArgumentMapper()->mapProperty(oaProperty(['property' => 'name'])))->toBeNull();
});

it('maps a query parameter to name plus scalar schema args', function (): void {
    $arguments = new OaAttributeArgumentMapper()->mapQueryParameter(oaQueryParameter([
        'name' => 'q',
        'required' => true,
        'description' => 'Free-text search.',
        'schema' => new OA\Schema(['type' => 'string', 'maxLength' => 50]),
    ]));

    expect($arguments)->toBe([
        'name' => 'q',
        'required' => true,
        'description' => 'Free-text search.',
        'type' => 'string',
        'maxLength' => 50,
    ]);
});

it('omits a non-required query parameter flag', function (): void {
    $arguments = new OaAttributeArgumentMapper()->mapQueryParameter(oaQueryParameter([
        'name' => 'limit',
        'schema' => new OA\Schema(['type' => 'integer']),
    ]));

    expect($arguments)->toBe(['name' => 'limit', 'type' => 'integer']);
});

it('refuses a non-query parameter', function (): void {
    foreach (['path', 'header', 'cookie'] as $location) {
        $parameter = new OA\Parameter([
            'name' => 'id',
            'in' => $location,
            'schema' => new OA\Schema(['type' => 'string']),
        ]);

        expect(new OaAttributeArgumentMapper()->mapQueryParameter($parameter))->toBeNull();
    }
});

it('refuses a query parameter carrying an enum schema', function (): void {
    $schema = new OA\Schema(['type' => 'string']);
    $schema->enum = ['asc', 'desc'];

    $parameter = oaQueryParameter(['name' => 'sort', 'schema' => $schema]);

    expect(new OaAttributeArgumentMapper()->mapQueryParameter($parameter))->toBeNull();
});

it('returns null for a query parameter carrying only its name', function (): void {
    expect(new OaAttributeArgumentMapper()->mapQueryParameter(oaQueryParameter(['name' => 'q'])))
        ->toBeNull();
});

it('refuses a query parameter whose schema carries a non-scalar key', function (): void {
    $refSchema = new OA\Schema([]);
    $refSchema->ref = '#/components/schemas/Filter';

    $parameter = oaQueryParameter(['name' => 'filter', 'schema' => $refSchema]);

    expect(new OaAttributeArgumentMapper()->mapQueryParameter($parameter))->toBeNull();
});
