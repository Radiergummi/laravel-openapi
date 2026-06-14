<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Tests\Fixtures\AdditionalPropertiesOverrideFixtureData;

use function array_column;
use function array_filter;

uses()->group('openapi', 'plugin:spatie-data');

class AdditionalPropertiesOverrideController extends Controller
{
    public function map(): AdditionalPropertiesOverrideFixtureData
    {
        return new AdditionalPropertiesOverrideFixtureData(closedMap: [], retypedMap: []);
    }

    public function show(
        #[PathParam(description: 'The widget.', additionalProperties: false)]
        string $widget,
    ): JsonResponse {
        return new JsonResponse();
    }
}

it('lets an explicit additionalProperties: false override #334 map inference', function (): void {
    Route::get('/additional-props/map', [AdditionalPropertiesOverrideController::class, 'map']);

    $props = generateSpec()['components']['schemas']['AdditionalPropertiesOverrideFixtureData']['properties'];

    // #334 would infer additionalProperties: {$ref: AddressFixtureData}; the attribute runs last.
    expect($props['closedMap']['additionalProperties'])->toBeFalse();
});

it('lets an explicit additionalProperties type-string override #334 map inference', function (): void {
    Route::get('/additional-props/map', [AdditionalPropertiesOverrideController::class, 'map']);

    $props = generateSpec()['components']['schemas']['AdditionalPropertiesOverrideFixtureData']['properties'];

    expect($props['retypedMap']['additionalProperties'])->toBe(['type' => 'string']);
});

it('honors #[PathParam(additionalProperties:)] on the parameter schema via toSchema()', function (): void {
    Route::get('/additional-props/{widget}', [AdditionalPropertiesOverrideController::class, 'show']);

    $operation = generateSpec()['paths']['/additional-props/{widget}']['get'];

    $widget = array_column(
        array_filter(
            $operation['parameters'] ?? [],
            static fn(array $parameter): bool => ($parameter['in'] ?? null) === 'path',
        ),
        null,
        'name',
    )['widget'] ?? null;

    // Near-meaningless on a scalar path param; the assertion guards that toSchema() does not drop
    // the field (the UriParametersExtractor path bypasses applyTo()).
    expect($widget)->not->toBeNull()
        ->and($widget['schema']['additionalProperties'])->toBeFalse();
});
