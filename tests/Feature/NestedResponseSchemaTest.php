<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\NestedResponseSchemaFixtureController;

uses()->group('openapi');

beforeEach(function (): void {
    Route::get('/oa-fixture/nested-response', [NestedResponseSchemaFixtureController::class, 'show']);
    $this->spec = generateSpec();
});

it('produces a swagger-php-valid document for a nested literal #[Response(schema:)]', function (): void {
    // The real `openapi:generate` validates the document (GenerateCommand::validate); generateSpec()
    // does not. Run the validator here so this guards the actual command path.
    $registry = app(SpecRegistry::class);
    $document = app(OpenApiGenerator::class)->generate($registry->default(), app()->environment());

    expect($document->validate())->toBeTrue();
});

it('emits a nested object/array schema from a literal #[Response(schema:)]', function (): void {
    $schema = $this->spec['paths']['/oa-fixture/nested-response']['get']['responses'][200]['content']['application/json']['schema'] ?? [];

    expect($schema['type'])
        ->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['message', 'tags'])
        ->and($schema['properties']['message']['type'])->toBe('string')
        ->and($schema['properties']['tags']['type'])->toBe('array')
        ->and($schema['properties']['tags']['items']['type'])->toBe('string')
        ->and($schema['required'])->toBe(['message']);
});
