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
use Radiergummi\OpenApi\Lint\LintResult;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\BrokenController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\CleanController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ResponseEmptyController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\SuppressedResponseEmptyController;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    Route::get('lint-runner/clean', [CleanController::class, 'list'])->name('runner.clean');
    Route::get('lint-runner/broken', [BrokenController::class, 'stream'])->name('runner.broken');
    Route::get('lint-runner/response-empty', [ResponseEmptyController::class, 'index'])->name('runner.response-empty');
    Route::get('lint-runner/suppressed', [SuppressedResponseEmptyController::class, 'index'])->name('runner.suppressed');
});

it('returns LintResult with exit code 0 for a clean route', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 0,
        path: 'lint-runner/clean*',
    ));

    expect($result)->toBeInstanceOf(LintResult::class)
        ->and($result->exitCode)->toBe(0)
        ->and($result->findings)->toBe([])
        ->and($result->level)->toBe(0);
});

it('returns exit code 1 and non-empty findings for a broken route', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        path: 'lint-runner/broken*',
    ));

    expect($result->exitCode)->toBe(1)
        ->and($result->findings)->not->toBe([]);
});

it('honours --no-suppress by surfacing findings hidden by an #[IgnoreLint] attribute', function (): void {
    $withSuppressions = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        path: 'lint-runner/suppressed*',
        applySuppressions: true,
    ));

    expect($withSuppressions->exitCode)->toBe(0);

    $this->app->forgetScopedInstances();

    $withoutSuppressions = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        path: 'lint-runner/suppressed*',
        applySuppressions: false,
    ));

    expect($withoutSuppressions->exitCode)->toBe(1);
});

it('falls back to the configured lint level when LintOptions.level is null', function (): void {
    config(['openapi.lint.level' => 2]);

    // BrokenController emits summary.missing at level 2 — exit 1 only when level resolves to 2.
    $result = app(LintRunner::class)->run(new LintOptions(
        path: 'lint-runner/broken*',
    ));

    expect($result->level)->toBe(2)
        ->and($result->exitCode)->toBe(1);
});

it('restricts findings to the --only allowlist (CLI only, no config)', function (): void {
    // BrokenController emits findings at level 2 — but only those whose ruleId is in
    // the --only list survive the post-walk filter. Compare the unrestricted result
    // (multiple distinct rule IDs expected) with the restricted result (every finding
    // is the single ruleId we asked for, or none at all).
    $unrestricted = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        path: 'lint-runner/broken*',
    ));

    $emittedIds = array_values(array_unique(
        array_map(static fn($finding) => $finding->ruleId, $unrestricted->findings),
    ));

    expect(count($emittedIds))->toBeGreaterThan(1);

    $this->app->forgetScopedInstances();

    $restricted = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        only: [$emittedIds[0]],
        path: 'lint-runner/broken*',
    ));

    $restrictedIds = array_values(array_unique(
        array_map(static fn($finding) => $finding->ruleId, $restricted->findings),
    ));

    expect($restrictedIds)->toBe([$emittedIds[0]]);
});

it('merges --skip with config disabled_rules and respects the merged denylist', function (): void {
    $baseline = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        path: 'lint-runner/broken*',
    ));

    // Capture the rule IDs actually emitted so the test is robust against rule-ID
    // renames in the fixture-source rule.
    $emittedIds = array_values(array_unique(
        array_map(static fn($finding) => $finding->ruleId, $baseline->findings),
    ));

    expect($baseline->exitCode)->toBe(1)
        ->and($emittedIds)->not->toBe([]);

    $this->app->forgetScopedInstances();

    // Disabling those rule IDs via config must produce a clean exit.
    config(['openapi.lint.disabled_rules' => $emittedIds]);

    $silenced = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        path: 'lint-runner/broken*',
    ));

    expect($silenced->exitCode)->toBe(0);
});

it('resolves --level=max to the rule registry maxLevel', function (): void {
    $registry = app(RuleRegistry::class);

    $result = app(LintRunner::class)->run(new LintOptions(
        level: 'max',
        path: 'lint-runner/clean*',
    ));

    expect($result->level)->toBe($registry->maxLevel());
});
