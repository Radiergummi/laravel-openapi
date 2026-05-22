<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Tests\Unit\Core\Generator\Fixtures\SmokeController;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi');

it('assembles a valid OpenAPI 3.1 document from a minimal route set', function (): void {
    Route::get('/things', [SmokeController::class, 'plain']);

    $document = app(OpenApiGenerator::class)->generate();

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

    $document = app(OpenApiGenerator::class)->generate();

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

it('does not leak component schemas between scopes', function (): void {
    // First run: register a route, generate the document, drop something into the registry.
    Route::get('/things', [SmokeController::class, 'plain']);
    app(OpenApiGenerator::class)->generate();

    $registry = app(ComponentSchemaRegistry::class);
    $registry->registerNamed('LeakedSchema', new OA\Schema(['type' => 'object']));

    expect($registry->hasKey('LeakedSchema'))->toBeTrue();

    // A fresh scope (the boundary Octane resets between requests) yields fresh
    // instances of every scoped pipeline binding, including the registry.
    app()->forgetScopedInstances();

    expect(app(ComponentSchemaRegistry::class)->hasKey('LeakedSchema'))->toBeFalse();
});

it('respects additional filters passed to generate()', function (): void {
    Route::get('/keep', [SmokeController::class, 'plain']);
    Route::get('/drop', [SmokeController::class, 'plain']);

    $document = app(OpenApiGenerator::class)->generate(filters: [
        static fn($descriptor): bool => $descriptor->route->uri() === 'drop',
    ]);

    $parsed = Yaml::parse($document->toYaml());

    expect($parsed['paths'])
        ->toHaveKey('/keep')
        ->not->toHaveKey('/drop');
});
