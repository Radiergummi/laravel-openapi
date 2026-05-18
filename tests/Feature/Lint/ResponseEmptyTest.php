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

uses()->group('openapi', 'lint');

it('emits response.empty for non-DELETE endpoints with no resolvable response schema', function (): void {
    Route::get('lint-test/empty-response', static fn() => response()->json(['ok' => true]))
        ->name('lint-test.empty-response');

    $collector = new ArrayFindingsCollector();

    // Scoped bindings may already be resolved; clear them so our collector
    // is picked up when the pipeline resolves FindingsCollector fresh.
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(Radiergummi\OpenApi\Core\Generator\OpenApiGenerator::class)->generate();

    $findings = collect($collector->all())
        ->filter(static fn($f) => $f->ruleId === 'response.empty' && $f->location->routeName === 'lint-test.empty-response');

    expect($findings)->toHaveCount(1);
});
