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
use Radiergummi\OpenApi\Core\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Plugins\JsonApi\QueryParametersExtractor;

uses()->group('openapi');

/*
 * OAPI-035 — ComponentSchemaRegistry singleton concurrency.
 *
 * Verifies that the OpenAPI pipeline bindings are registered as `scoped`
 * (not `singleton`) so that Octane-style request isolation gives each
 * generation run a fresh instance. Under Octane the container calls
 * `forgetScopedInstances()` between requests; we simulate that here.
 */

it('OAPI-035: ComponentSchemaRegistry is fresh after forgetScopedInstances', function (): void {
    $first = app(ComponentSchemaRegistry::class);

    // Pollute the first instance so we can detect cross-run leakage.
    /** @var class-string $sentinel */
    $sentinel = ComponentSchemaRegistry::class;
    $first->reserveKey($sentinel);

    expect($first->isRegisteredOrReserved($sentinel))->toBeTrue();

    // Simulate Octane's between-request reset.
    app()->forgetScopedInstances();

    $second = app(ComponentSchemaRegistry::class);

    expect($second)->not->toBe($first)
        ->and($second->isRegisteredOrReserved($sentinel))->toBeFalse('state from the first run must not bleed into the second');
});

it('OAPI-035: QueryParametersExtractor is fresh after forgetScopedInstances', function (): void {
    $first = app(QueryParametersExtractor::class);
    $second_before_reset = app(QueryParametersExtractor::class);

    // Within the same scope the container must return the same instance.
    expect($second_before_reset)->toBe($first);

    app()->forgetScopedInstances();

    $after_reset = app(QueryParametersExtractor::class);

    expect($after_reset)->not->toBe($first);
});

it('OAPI-035: ExampleFileLoader is fresh after forgetScopedInstances', function (): void {
    $first = app(ExampleFileLoader::class);

    app()->forgetScopedInstances();

    $second = app(ExampleFileLoader::class);

    expect($second)->not->toBe($first);
});

it('OAPI-035: OpenApiGenerator is fresh after forgetScopedInstances', function (): void {
    $first = app(OpenApiGenerator::class);

    app()->forgetScopedInstances();

    $second = app(OpenApiGenerator::class);

    expect($second)->not->toBe($first);
});

it('OAPI-035: within a single scope all resolutions return the same ComponentSchemaRegistry instance', function (): void {
    // Ensures the graph is consistent — every class in the pipeline shares the same
    // registry object within a single generation run.
    $directRegistry = app(ComponentSchemaRegistry::class);

    // Re-resolving within the same scope must return the identical instance.
    $sameRegistry = app(ComponentSchemaRegistry::class);

    expect($sameRegistry)->toBe($directRegistry);
});
