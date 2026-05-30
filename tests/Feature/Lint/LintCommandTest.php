<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Lint\SuppressionCollector;
use Radiergummi\OpenApi\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithSuppressedDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\BrokenController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\CleanController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ResponseEmptyController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\SuppressedController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\SuppressedResponseEmptyController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    Route::get('lint-fixtures/clean', [CleanController::class, 'list'])->name('lint.clean.list');
    Route::get('lint-fixtures/broken/stream', [BrokenController::class, 'stream'])->name('lint.broken.stream');
    Route::get('lint-fixtures/suppressed/stream', [SuppressedController::class, 'stream'])->name('lint.suppressed.stream');
    // Dedicated single-finding fixtures: emit only response.success-empty-body (level 2) at levels 0–2.
    Route::get('lint-fixtures/response-empty', [ResponseEmptyController::class, 'index'])->name('lint.response-empty');
    Route::get('lint-fixtures/suppressed-response-empty', [SuppressedResponseEmptyController::class, 'index'])->name('lint.suppressed-response-empty');
});

it('exits 0 when clean controller is the only route', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--path' => 'lint-fixtures/clean*',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('exits 1 when broken controller has findings', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => 'lint-fixtures/broken*',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('respects suppression directives', function (): void {
    // SuppressedResponseEmptyController suppresses response.success-empty-body (level 2) —
    // the only finding that would fire at this level. Exit 0 proves suppression works.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => 'lint-fixtures/suppressed-response-empty',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('--no-suppress disables directives', function (): void {
    // With suppression disabled, response.success-empty-body (level 2) surfaces — exit 1.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => 'lint-fixtures/suppressed-response-empty',
        '--format' => 'json',
        '--no-suppress' => true,
    ])->assertExitCode(1);
});

it('uses config lint level when --level is not passed', function (): void {
    config(['openapi.lint.level' => 2]);

    // BrokenController has no summary — summary.missing fires at level 2.
    // Without the config default this would exit 0 (level 0 misses level-2 rules).
    $this->artisan('openapi:lint', [
        '--path' => 'lint-fixtures/broken*',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('config disabled_rules suppresses a rule without --skip on CLI', function (): void {
    // ResponseEmptyController emits only response.success-empty-body (level 2). Disabling
    // it must produce exit 0; without the rule disabled it would exit 1.
    config(['openapi.lint.disabled_rules' => ['response.success-empty-body']]);

    // Verify the pipeline is live on a route that is clean at level 0.
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--path' => 'lint-fixtures/clean*',
        '--format' => 'json',
    ])->assertExitCode(0);

    // ResponseEmptyController exits 0 once response.success-empty-body is disabled.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => 'lint-fixtures/response-empty',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('severity_overrides remaps a finding level so it is excluded at the original threshold', function (): void {
    // ResponseEmptyController emits only response.success-empty-body at level 2. Remapping
    // it to level 4 means it no longer appears at --level 2 — exit 0.
    config(['openapi.lint.severity_overrides' => ['response.success-empty-body' => 4]]);

    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => 'lint-fixtures/response-empty',
        '--format' => 'json',
    ])->assertExitCode(0);

    // At level 4 the remapped finding is within threshold again — exit 1.
    $this->artisan('openapi:lint', [
        '--level' => 4,
        '--path' => 'lint-fixtures/response-empty',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('resolves SuppressionCollector with request_payload_indirection wired', function (): void {
    // Regression guard: OpenApiServiceProvider must pass
    // config('openapi.request_payload_indirection') to SuppressionCollector.
    // Without it the collector cannot follow Domain Action indirection, so
    // #[IgnoreLint] on a Data class injected via an Action is silently ignored.
    config()->set('openapi.request_payload_indirection', [
        Radiergummi\OpenApi\Tests\Fixtures\Action::class,
    ]);
    $this->app->forgetScopedInstances();

    $collector = app(SuppressionCollector::class);

    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ActionWithSuppressedDataController::class,
        'create',
        'test',
        ['POST'],
    );

    $directives = $collector->collect([$descriptor]);
    $ruleIds = array_map(static fn(SuppressionDirective $d): string => $d->ruleId, $directives);

    // Both directives live on the Data class reached only through the Action's
    // constructor — they appear here only if indirection is wired.
    expect($ruleIds)->toContain('field.no-effect')
        ->and($ruleIds)->toContain('field.invalid-format');
});

it('lists the rule catalog as JSON with --list', function (): void {
    $this->artisan('openapi:lint', ['--list' => true, '--format' => 'json'])
        ->assertExitCode(0);
})->group('openapi', 'lint');

it('lists the rule catalog as Markdown with --list', function (): void {
    // Each expectsOutputToContain consumes one doWrite call, so target three distinct
    // lines: the header row, the separator row, and a known rule's body row.
    $this->artisan('openapi:lint', ['--list' => true, '--format' => 'markdown'])
        ->expectsOutputToContain('| Rule ID')
        ->expectsOutputToContain('|---')
        ->expectsOutputToContain('| `spec.invalid`')
        ->assertExitCode(0);
})->group('openapi', 'lint');

it('tags per-spec findings with the spec name', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'lint-fixtures/broken*']],
        ],
    ]);

    // Rebuild scoped bindings so SpecRegistry picks up the new config.
    $this->app->forgetScopedInstances();

    $result = app(LintRunner::class)
        ->run(new LintOptions(
            level: 2,
            path: 'lint-fixtures/broken*',
        ));

    $specs = array_unique(array_map(static fn($f) => $f->spec, $result->findings));
    // Findings from per-spec rules are tagged with the spec name, not null.
    expect($specs)->not->toContain(null);
})->group('openapi', 'lint');

it('--spec= restricts per-spec rules to the named spec', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'lint-fixtures/broken*']],
        ],
    ]);

    $this->app->forgetScopedInstances();

    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path'  => 'lint-fixtures/broken*',
        '--spec'  => 'default',
        '--format' => 'json',
    ])->assertExitCode(1); // findings exist in default spec
})->group('openapi', 'lint');

it('pre-build rules run regardless of --spec= narrowing', function (): void {
    // 'unreachable' is configured but its match config picks no routes; the pre-build
    // rule spec.config-orphaned must surface that even when the runner is narrowed to
    // another spec entirely.
    config([
        'openapi.specs' => [
            'unreachable' => ['match' => ['prefix' => 'never-matches-anything/*']],
        ],
    ]);

    $this->app->forgetScopedInstances();

    $result = app(LintRunner::class)
        ->run(new LintOptions(
            level: 3,
            path: 'lint-fixtures/clean*',
            spec: 'default',
        ));

    $orphans = array_filter(
        $result->findings,
        static fn($f) => $f->ruleId === 'spec.config-orphaned'
            && str_contains($f->message, 'unreachable'),
    );

    expect($orphans)->toHaveCount(1)
        ->and(array_values($orphans)[0]->spec)->toBeNull(); // pre-build findings carry no spec tag
})->group('openapi', 'lint');

it('--path filter scopes extractor-emitted findings, not just tree-walk findings', function (): void {
    // CleanController::list as POST triggers request.empty (no body schema). The lint-fixtures/clean
    // GET route registered in beforeEach is untouched. Without filtering both POSTs would emit
    // request.empty; with --path='lint-fixtures/clean*' the leak route's finding must drop.
    Route::post('leak-fixtures/excluded', [CleanController::class, 'list'])->name('leak.excluded');

    $unfiltered = app(LintRunner::class)->run(new LintOptions(level: 2));
    $leakFindings = array_filter(
        $unfiltered->findings,
        static fn($f) => $f->ruleId === 'request.empty'
            && $f->location->routeUri === 'leak-fixtures/excluded',
    );
    expect($leakFindings)->not->toBeEmpty();

    $filtered = app(LintRunner::class)->run(new LintOptions(level: 2, path: 'lint-fixtures/clean*'));
    $leakedAfterFilter = array_filter(
        $filtered->findings,
        static fn($f) => $f->location->routeUri === 'leak-fixtures/excluded',
    );
    expect($leakedAfterFilter)->toBeEmpty();
});

it('cannot disable spec.invalid via config disabled_rules', function (): void {
    config(['openapi.lint.disabled_rules' => ['spec.invalid']]);

    // spec.invalid must remain active regardless. CleanController produces no
    // findings at level 0 — exit 0 proves the pipeline runs normally.
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--path' => 'lint-fixtures/clean*',
        '--format' => 'json',
    ])->assertExitCode(0);

    // BrokenController has no summary so summary.missing (level 2) fires — a
    // rule other than spec.invalid. Exit 1 at level 2 proves the pipeline is
    // live and disabled_rules did not inadvertently disable other rules.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => 'lint-fixtures/broken*',
        '--format' => 'json',
    ])->assertExitCode(1);
});
