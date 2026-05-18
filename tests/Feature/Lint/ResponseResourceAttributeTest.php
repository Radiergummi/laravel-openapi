<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\BadResponseResourceController;

uses()->group('openapi', 'lint');

it('emits responseresource.unresolvable when #[ResponseResource] points to a non-ApiResource class', function (): void {
    Route::get('lint-test/bad-response-resource', [
        BadResponseResourceController::class, 'index',
    ])->name('lint-test.bad-response-resource');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(OpenApiGenerator::class)->generate();

    expect(collect($collector->all())->pluck('ruleId')->all())
        ->toContain('responseresource.unresolvable');
});
