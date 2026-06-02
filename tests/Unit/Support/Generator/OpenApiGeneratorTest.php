<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Unit\Support\Generator\Fixtures\SmokeController;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi');

it('assembles a valid OpenAPI 3.1 document from a minimal route set', function (): void {
    Route::get('/things', [SmokeController::class, 'plain']);

    $spec = app(SpecRegistry::class)->default();
    $document = app(OpenApiGenerator::class)->generate($spec, 'testing');

    expect($document)->toBeInstanceOf(OA\OpenApi::class)
        ->and($document->openapi)->toBe('3.1.0')
        ->and($document->info)->toBeInstanceOf(OA\Info::class);

    $parsed = Yaml::parse($document->toYaml());

    expect($parsed['openapi'])->toStartWith('3.1')
        ->and($parsed)->toHaveKeys(['info', 'paths'])
        ->and($parsed['paths'])->toHaveKey('/things');
});

it('serialises to both YAML and JSON', function (): void {
    Route::get('/things', [SmokeController::class, 'plain']);

    $spec = app(SpecRegistry::class)->default();
    $document = app(OpenApiGenerator::class)->generate($spec, 'testing');

    $yaml = $document->toYaml();
    $json = $document->toJson();

    expect(Yaml::parse($yaml))
        ->toBeArray()
        ->toHaveKey('paths');

    $decoded = json_decode($json, true);

    expect($decoded)
        ->toBeArray()
        ->toHaveKey('paths');
});

it('produces structurally equivalent YAML and JSON from a single generation', function (): void {
    Route::get('/things', [SmokeController::class, 'plain']);

    $spec = app(SpecRegistry::class)->default();
    $document = app(OpenApiGenerator::class)->generate($spec, 'testing');

    // Both serialisers must encode the same document; a divergence means one path mutates or
    // drops state the other keeps.
    expect(Yaml::parse($document->toYaml()))
        ->toEqual(json_decode($document->toJson(), true));
});

it('does not leak component schemas between scopes', function (): void {
    // First run: register a route, generate the document, drop something into the registry.
    Route::get('/things', [SmokeController::class, 'plain']);
    $spec = app(SpecRegistry::class)->default();
    app(OpenApiGenerator::class)->generate($spec, 'testing');

    $registry = app(ComponentSchemaRegistry::class);
    $registry->registerNamed('LeakedSchema', new OA\Schema(['type' => 'object']));

    expect($registry->hasKey('LeakedSchema'))->toBeTrue();

    // A fresh scope (the boundary Octane resets between requests) yields fresh
    // instances of every scoped pipeline binding, including the registry.
    app()->forgetScopedInstances();

    expect(app(ComponentSchemaRegistry::class)->hasKey('LeakedSchema'))->toBeFalse();
});

it('excludes a route when a RouteFilter in config returns shouldSkip=true', function (): void {
    Route::get('/keep', [SmokeController::class, 'plain']);
    Route::get('/drop', [SmokeController::class, 'plain']);

    // Register a filter via config — filters now live in the InclusionEvaluator, not generate().
    config(['openapi.filters' => [
        new class () implements RouteFilter {
            public function shouldSkip(Illuminate\Routing\Route $route): bool
            {
                return $route->uri() === 'drop';
            }
        },
    ]]);

    // Forget scoped instances so the InclusionEvaluator re-reads the config filter.
    app()->forgetScopedInstances();

    $spec = app(SpecRegistry::class)->default();
    $document = app(OpenApiGenerator::class)->generate($spec, 'testing');

    $parsed = Yaml::parse($document->toYaml());

    expect($parsed['paths'])
        ->toHaveKey('/keep')
        ->not->toHaveKey('/drop');
});
