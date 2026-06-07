<?php

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
        uriGlob: 'lint-runner/clean*',
    ));

    expect($result)->toBeInstanceOf(LintResult::class)
        ->and($result->exitCode)->toBe(0)
        ->and($result->findings)->toBe([])
        ->and($result->level)->toBe(0);
});

it('returns exit code 1 and non-empty findings for a broken route', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
    ));

    expect($result->exitCode)->toBe(1)
        ->and($result->findings)->not->toBe([]);
});

it('honours --no-suppress by surfacing findings hidden by an #[IgnoreLint] attribute', function (): void {
    $withSuppressions = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/suppressed*',
        applySuppressions: true,
    ));

    expect($withSuppressions->exitCode)->toBe(0);

    $this->app->forgetScopedInstances();

    $withoutSuppressions = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/suppressed*',
        applySuppressions: false,
    ));

    expect($withoutSuppressions->exitCode)->toBe(1);
});

it('falls back to the configured lint level when LintOptions.level is null', function (): void {
    config(['openapi.lint.level' => 2]);

    // BrokenController emits summary.missing at level 2 — exit 1 only when level resolves to 2.
    $result = app(LintRunner::class)->run(new LintOptions(
        uriGlob: 'lint-runner/broken*',
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
        uriGlob: 'lint-runner/broken*',
    ));

    $emittedIds = array_values(array_unique(
        array_map(static fn($finding) => $finding->ruleId, $unrestricted->findings),
    ));

    expect(count($emittedIds))->toBeGreaterThan(1);

    $this->app->forgetScopedInstances();

    $restricted = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        only: [$emittedIds[0]],
        uriGlob: 'lint-runner/broken*',
    ));

    $restrictedIds = array_values(array_unique(
        array_map(static fn($finding) => $finding->ruleId, $restricted->findings),
    ));

    expect($restrictedIds)->toBe([$emittedIds[0]]);
});

it('merges --skip with config disabled_rules and respects the merged denylist', function (): void {
    $baseline = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
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
        uriGlob: 'lint-runner/broken*',
    ));

    expect($silenced->exitCode)->toBe(0);
});

it('resolves --level=max to the rule registry maxLevel', function (): void {
    $registry = app(RuleRegistry::class);

    $result = app(LintRunner::class)->run(new LintOptions(
        level: 'max',
        uriGlob: 'lint-runner/clean*',
    ));

    expect($result->level)->toBe($registry->maxLevel());
});

it('attaches a coverage summary to the result', function (): void {
    // The clean fixture is only finding-free at level 0; at level >= 1 it emits response rules.
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 0,
        uriGlob: 'lint-runner/clean*',
    ));

    expect($result->coverage)->not->toBeNull()
        ->and($result->coverage->totalOperations)->toBe(1)
        ->and($result->coverage->coveredOperations)->toBe(1)
        ->and($result->coverage->coveragePercent)->toBe(100.00)
        ->and($result->coverage->generatorVersion)->toBeString()->not->toBe('');
});

it('reports reduced coverage for a broken route', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
    ));

    expect($result->coverage->totalOperations)->toBe(1)
        ->and($result->coverage->coveredOperations)->toBe(0)
        ->and($result->coverage->coveragePercent)->toBe(0.00);
});

it('keeps legacy exit semantics when no gate is configured', function (): void {
    $broken = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
    ));

    expect($broken->exitCode)->toBe(1);
});

it('passes the min-coverage gate when coverage meets the threshold despite findings', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
        minCoverage: 0.0,
    ));

    expect($result->findings)->not->toBe([])
        ->and($result->exitCode)->toBe(0);
});

it('fails the min-coverage gate when coverage is below the threshold', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
        minCoverage: 100.0,
    ));

    expect($result->exitCode)->toBe(1);
});

it('fails the max-findings gate when the finding count exceeds the budget', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
        maxFindings: 0,
    ));

    expect($result->exitCode)->toBe(1);
});

it('passes a generous max-findings gate even with findings present', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
        maxFindings: 999,
    ));

    expect($result->findings)->not->toBe([])
        ->and($result->exitCode)->toBe(0);
});

it('activates the gate from config when no CLI flag is passed', function (): void {
    config(['openapi.lint.min_coverage' => 100]);

    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/broken*',
    ));

    expect($result->exitCode)->toBe(1);
});

it('treats an empty scope as 100% coverage under a gate', function (): void {
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 2,
        uriGlob: 'lint-runner/does-not-exist*',
        minCoverage: 100.0,
    ));

    expect($result->coverage->totalOperations)->toBe(0)
        ->and($result->coverage->coveragePercent)->toBe(100.00)
        ->and($result->exitCode)->toBe(0);
});
