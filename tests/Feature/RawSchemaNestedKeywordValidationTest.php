<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword\NestedKeywordController;

uses()->group('openapi');

/**
 * Resolves the request-body component schema referenced from a path operation.
 *
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function nestedKeywordComponentFor(array $spec, string $uri): array
{
    $ref = $spec['paths'][$uri]['post']['requestBody']['content']['application/json']['schema']['$ref'];
    $name = basename(str_replace('#/components/schemas/', '', $ref));

    return $spec['components']['schemas'][$name] ?? [];
}

beforeEach(function (): void {
    Route::post('/oa-fixture/raw-additional-properties-schema', [NestedKeywordController::class, 'additionalPropertiesSchema']);
    Route::post('/oa-fixture/raw-pattern-properties', [NestedKeywordController::class, 'patternProperties']);
    Route::post('/oa-fixture/raw-property-names', [NestedKeywordController::class, 'propertyNames']);
    Route::post('/oa-fixture/raw-contains', [NestedKeywordController::class, 'contains']);
    Route::post('/oa-fixture/raw-discriminator', [NestedKeywordController::class, 'discriminator']);
    Route::post('/oa-fixture/raw-additional-properties-bool', [NestedKeywordController::class, 'additionalPropertiesBool']);
});

it('produces a swagger-php-valid document for all nested #[RawSchema] keywords', function (): void {
    // The real `openapi:generate` validates the document (GenerateCommand::validate); generateSpec()
    // does not. Run the validator here so this guards the actual command path on every supported leg.
    $registry = app(SpecRegistry::class);
    $document = app(OpenApiGenerator::class)->generate($registry->default(), app()->environment());

    expect($document->validate())->toBeTrue();
});

it('keeps the nested schema under additionalProperties', function (): void {
    $component = nestedKeywordComponentFor(generateSpec(), '/oa-fixture/raw-additional-properties-schema');

    expect($component['additionalProperties']['type'])
        ->toBe('array')
        ->and($component['additionalProperties']['items']['type'])->toBe('string');
});

it('keeps the nested schemas under patternProperties', function (): void {
    $component = nestedKeywordComponentFor(generateSpec(), '/oa-fixture/raw-pattern-properties');

    expect($component['patternProperties']['^x-']['type'])
        ->toBe('string')
        ->and($component['patternProperties']['^count_']['type'])->toBe('integer');
});

it('keeps the nested schema under propertyNames', function (): void {
    $component = nestedKeywordComponentFor(generateSpec(), '/oa-fixture/raw-property-names');

    expect($component['propertyNames']['pattern'])->toBe('^[a-z]+$');
});

it('keeps the nested schema under contains', function (): void {
    $component = nestedKeywordComponentFor(generateSpec(), '/oa-fixture/raw-contains');

    expect($component['contains']['type'])
        ->toBe('integer')
        ->and($component['contains']['minimum'])->toBe(1);
});

it('keeps the discriminator object alongside oneOf', function (): void {
    $component = nestedKeywordComponentFor(generateSpec(), '/oa-fixture/raw-discriminator');

    expect($component['oneOf'])
        ->toHaveCount(2)
        ->and($component['discriminator']['propertyName'])->toBe('kind')
        ->and($component['discriminator']['mapping'])->toHaveKeys(['circle', 'square']);
});

it('passes the additionalProperties bool form through unchanged', function (): void {
    $component = nestedKeywordComponentFor(generateSpec(), '/oa-fixture/raw-additional-properties-bool');

    expect($component['additionalProperties'])->toBeFalse();
});
