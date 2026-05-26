<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Alpha\SelfRefData as AlphaSelfRefData;
use Radiergummi\OpenApi\Tests\Fixtures\Beta\SelfRefData as BetaSelfRefData;
use Radiergummi\OpenApi\Tests\Fixtures\MapInputNameFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\PropertyFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\SchemaTitleDescriptionFixtureData;

uses()->group('openapi', 'plugin:spatie-data');

class PropertyDataController extends Controller
{
    public function store(PropertyFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

class MapInputNameController extends Controller
{
    public function store(MapInputNameFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

class AlphaSelfRefController extends Controller
{
    public function store(AlphaSelfRefData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

class BetaSelfRefController extends Controller
{
    public function store(BetaSelfRefData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

class SchemaTitleDescriptionController extends Controller
{
    public function store(SchemaTitleDescriptionFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

// region #[RequestField] attribute fields

it('applies #[RequestField] description, example and maxLength to schema properties', function (): void {
    Route::post('/spatie-data/property', [PropertyDataController::class, 'store']);

    $spec = generateSpec();

    $props = $spec['components']['schemas']['PropertyFixtureData']['properties'];

    expect($props)->toHaveKeys(['name', 'callbackUrl', 'limit'])
        ->and($props['name']['description'])->toBe('Display name shown in lists.')
        ->and($props['name']['example'])->toBe('Aerospace Q1')
        ->and($props['name']['maxLength'])->toBe(250)
        ->and($props['name']['type'])->toBe('string')
        ->and($props['callbackUrl']['format'])->toBe('uri')
        ->and($props['callbackUrl']['example'])->toBe('https://hooks.example.com/projects');
});

it('leaves properties without #[RequestField] description-free', function (): void {
    Route::post('/spatie-data/property', [PropertyDataController::class, 'store']);

    $spec = generateSpec();

    $props = $spec['components']['schemas']['PropertyFixtureData']['properties'];

    // No authoring annotation means no description; the Faker example-synthesis pass may still
    // populate an example slot from the property's PHP type, since that is a non-authored
    // fallback applied uniformly across FormRequests and Data classes.
    expect($props['limit']['type'])->toBe('integer')
        ->and($props['limit'])->not->toHaveKey('description');
});

// endregion

// region OAPI-001: #[MapInputName] resolution

dataset('map input name cases', [
    'literal wire name from attribute string' => ['literal_name', 'literalName'],
    'NameMapper class (SnakeCaseMapper)'      => ['mapper_name', 'mapperName'],
    'unmapped property keeps its PHP name'    => ['unmapped', null],
]);

it('renders schema property keys via #[MapInputName]', function (string $present, ?string $absent): void {
    Route::post('/spatie-data/map-input-name', [MapInputNameController::class, 'store']);

    $spec = generateSpec();

    $props = $spec['components']['schemas']['MapInputNameFixtureData']['properties'];

    expect($props)->toHaveKey($present);

    if ($absent !== null) {
        expect($props)->not->toHaveKey($absent);
    }
})->with('map input name cases');

it('uses wire names in required[] (Optional union drops the field)', function (): void {
    Route::post('/spatie-data/map-input-name', [MapInputNameController::class, 'store']);

    $spec = generateSpec();

    $required = $spec['components']['schemas']['MapInputNameFixtureData']['required'] ?? [];

    expect($required)->toContain('literal_name')
        ->and($required)->toContain('mapper_name')
        ->and($required)->toContain('unmapped')
        ->and($required)->not->toContain('optional_literal')
        ->and($required)->not->toContain('literalName')
        ->and($required)->not->toContain('mapperName');
});

// endregion

// region OAPI-008: Cycle-safe $ref and same-basename disambiguation

it('emits a $ref with the basename key for a self-referential Data class (OAPI-008)', function (): void {
    Route::post('/spatie-data/alpha-self-ref', [AlphaSelfRefController::class, 'store']);

    $spec = generateSpec();

    expect($spec['components']['schemas'])->toHaveKey('SelfRefData');

    $props = $spec['components']['schemas']['SelfRefData']['properties'];

    expect($props)->toHaveKey('child');

    // ?self renders as oneOf containing a {$ref} and a {type: 'null'}; order is not part of the contract.
    $refs = array_column($props['child']['oneOf'], '$ref');

    expect($refs)->toContain('#/components/schemas/SelfRefData');
});

it('disambiguates same-basename Data classes across namespaces (OAPI-008)', function (): void {
    Route::post('/spatie-data/alpha-self-ref', [AlphaSelfRefController::class, 'store']);
    Route::post('/spatie-data/beta-self-ref', [BetaSelfRefController::class, 'store']);

    $spec = generateSpec();

    $keys = array_keys($spec['components']['schemas']);

    expect($keys)->toContain('SelfRefData');

    $disambiguated = array_values(array_filter(
        $keys,
        static fn(string $k): bool => $k !== 'SelfRefData' && str_contains($k, 'SelfRefData'),
    ));

    expect($disambiguated)->toHaveCount(1);

    // The second-registered schema's `child` must reference the disambiguated key, not the basename.
    $betaSchemaKey = $disambiguated[0];
    $betaProps     = $spec['components']['schemas'][$betaSchemaKey]['properties'];

    expect($betaProps)->toHaveKey('child');

    $refs = array_column($betaProps['child']['oneOf'], '$ref');

    expect($refs)->toContain('#/components/schemas/' . $betaSchemaKey);
});

// endregion

// region #[Summary] / #[Description] on a Data class

it('maps #[Summary] to schema title and #[Description] to schema description', function (): void {
    Route::post('/spatie-data/titled', [SchemaTitleDescriptionController::class, 'store']);

    $schema = generateSpec()['components']['schemas']['SchemaTitleDescriptionFixtureData'];

    expect($schema['title'])->toBe('Fixture Title')
        ->and($schema['description'])->toBe('Fixture data class for schema-level title/description.');
});

// endregion
