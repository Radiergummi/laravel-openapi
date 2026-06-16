<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

uses()->group('openapi', 'lint');

it('emits request.empty for POST/PUT/PATCH with no resolvable request body', function (): void {
    Route::post('lint-test/empty-request', static fn() => response()->json(['ok' => true]))
        ->name('lint-test.empty-request');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app
        ->make(Radiergummi\OpenApi\Support\Generator\OpenApiGenerator::class)
        ->generate($this->app->make(SpecRegistry::class)->default(), 'testing');

    $findings = collect($collector->all())
        ->filter(
            static fn(
                Finding $finding,
            ) => $finding->ruleId === 'request.empty' && $finding->location->routeName === 'lint-test.empty-request',
        );

    expect($findings)->toHaveCount(1);
});
