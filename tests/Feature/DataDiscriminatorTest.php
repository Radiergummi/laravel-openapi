<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\BaseShapeData;

uses()->group('openapi', 'plugin:spatie-data');

class ShapeFixtureController extends Controller
{
    public function store(BaseShapeData $shape): JsonResponse
    {
        return new JsonResponse();
    }
}

beforeEach(function (): void {
    Route::post('/oa-027/shape', [ShapeFixtureController::class, 'store']);
});

it('OAPI-027: base Data class with #[Discriminator] emits oneOf instead of a flat object', function (): void {
    $schema = generateSpec()['components']['schemas']['BaseShapeData'];

    expect($schema)
        ->toHaveKey('oneOf')
        ->and($schema)->not
        ->toHaveKey('properties')
        ->and($schema)->not->toHaveKey('type');
});

it('OAPI-027: base Data class oneOf lists $ref entries for each variant', function (): void {
    $schema = generateSpec()['components']['schemas']['BaseShapeData'];

    $refs = array_column($schema['oneOf'], '$ref');

    expect($refs)
        ->toContain('#/components/schemas/CircleData')
        ->and($refs)->toContain('#/components/schemas/RectangleData');
});

it('OAPI-027: base Data class emits discriminator.propertyName', function (): void {
    $schema = generateSpec()['components']['schemas']['BaseShapeData'];

    expect($schema)
        ->toHaveKey('discriminator')
        ->and($schema['discriminator'])->toHaveKey('propertyName')
        ->and($schema['discriminator']['propertyName'])->toBe('type');
});

it('OAPI-027: base Data class discriminator.mapping points to $ref strings', function (): void {
    $schema = generateSpec()['components']['schemas']['BaseShapeData'];

    $mapping = $schema['discriminator']['mapping'] ?? [];

    expect($mapping)
        ->toHaveKey('circle')
        ->and($mapping['circle'])->toBe('#/components/schemas/CircleData')
        ->and($mapping)->toHaveKey('rectangle')
        ->and($mapping['rectangle'])->toBe('#/components/schemas/RectangleData');
});

it('OAPI-027: variant Data classes are registered as their own component schemas', function (): void {
    $schemas = generateSpec()['components']['schemas'];

    expect($schemas)
        ->toHaveKey('CircleData')
        ->and($schemas)->toHaveKey('RectangleData');
});

it('OAPI-027: variant Data class schemas are flat objects with their own properties', function (): void {
    $schemas = generateSpec()['components']['schemas'];

    expect($schemas['CircleData'])
        ->toHaveKey('properties')
        ->and($schemas['CircleData']['properties'])->toHaveKey('radius')
        ->and($schemas['RectangleData'])->toHaveKey('properties')
        ->and($schemas['RectangleData']['properties'])->toHaveKey('width')
        ->and($schemas['RectangleData']['properties'])->toHaveKey('height');
});
