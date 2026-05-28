<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Radiergummi\OpenApi\Core\Extraction\FormRequestRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\DataClassRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

uses()->group('openapi');

it('exposes lint.level defaulting to 1', function (): void {
    expect(config('openapi.lint.level'))->toBe(1);
});

it('lists the default plugins in config', function (): void {
    expect(config('openapi.plugins'))
        ->toContain(SpatieDataPlugin::class);
});

it('resolves a registry with core and plugin resolvers when the plugins are enabled', function (): void {
    $registry = app(OpenApiRegistry::class);

    expect($registry->requestSchemaResolvers())
        ->toContain(FormRequestRequestSchemaResolver::class)
        ->toContain(DataClassRequestSchemaResolver::class);
});

it('omits plugin resolvers when the plugins list is cleared', function (): void {
    config(['openapi.plugins' => []]);

    // The registry is scoped — forget cached instances so the factory re-runs
    // with the updated config value.
    app()->forgetScopedInstances();

    $registry = app(OpenApiRegistry::class);

    expect($registry->requestSchemaResolvers())
        ->toContain(FormRequestRequestSchemaResolver::class)
        ->not->toContain(DataClassRequestSchemaResolver::class);
});
