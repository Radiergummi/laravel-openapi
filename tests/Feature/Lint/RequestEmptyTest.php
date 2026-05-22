<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

uses()->group('openapi', 'lint');

it('emits request.empty for POST/PUT/PATCH with no resolvable request body', function (): void {
    Route::post('lint-test/empty-request', static fn() => response()->json(['ok' => true]))
        ->name('lint-test.empty-request');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(Radiergummi\OpenApi\Core\Generator\OpenApiGenerator::class)
        ->generate($this->app->make(SpecRegistry::class)->default(), 'testing');

    $findings = collect($collector->all())
        ->filter(static fn($f) => $f->ruleId === 'request.empty' && $f->location->routeName === 'lint-test.empty-request');

    expect($findings)->toHaveCount(1);
});
