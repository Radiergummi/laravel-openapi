<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint\IgnoreLintFixtureController;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    Route::post('ignore-lint/form-request', [IgnoreLintFixtureController::class, 'viaFormRequest']);
    Route::get('ignore-lint/json-resource', [IgnoreLintFixtureController::class, 'viaJsonResource']);
});

it('suppresses field.name-naming-inconsistent findings on a FormRequest with a class-level IgnoreLint', function (): void {
    $withoutSuppress = app(LintRunner::class)->run(new LintOptions(
        level: 3,
        only: ['field.name-naming-inconsistent'],
        path: 'ignore-lint/form-request*',
        applySuppressions: false,
    ));

    $this->app->forgetScopedInstances();

    $withSuppress = app(LintRunner::class)->run(new LintOptions(
        level: 3,
        only: ['field.name-naming-inconsistent'],
        path: 'ignore-lint/form-request*',
        applySuppressions: true,
    ));

    expect($withoutSuppress->findings)->not->toBe([])
        ->and($withSuppress->findings)->toBe([]);
});

it('suppresses field.name-naming-inconsistent findings on a JsonResource with a class-level IgnoreLint', function (): void {
    $withoutSuppress = app(LintRunner::class)->run(new LintOptions(
        level: 3,
        only: ['field.name-naming-inconsistent'],
        path: 'ignore-lint/json-resource*',
        applySuppressions: false,
    ));

    $this->app->forgetScopedInstances();

    $withSuppress = app(LintRunner::class)->run(new LintOptions(
        level: 3,
        only: ['field.name-naming-inconsistent'],
        path: 'ignore-lint/json-resource*',
        applySuppressions: true,
    ));

    expect($withoutSuppress->findings)->not->toBe([])
        ->and($withSuppress->findings)->toBe([]);
});
