<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\SuppressionCollector;
use Radiergummi\OpenApi\Core\Lint\SuppressionDirective;
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
    // Dedicated single-finding fixtures: emit only response.empty (level 2) at levels 0–2.
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
    // SuppressedResponseEmptyController suppresses response.empty (level 2) —
    // the only finding that would fire at this level. Exit 0 proves suppression works.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => 'lint-fixtures/suppressed-response-empty',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('--no-suppress disables directives', function (): void {
    // With suppression disabled, response.empty (level 2) surfaces — exit 1.
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
    // ResponseEmptyController emits only response.empty (level 2). Disabling it
    // must produce exit 0; without the rule disabled it would exit 1.
    config(['openapi.lint.disabled_rules' => ['response.empty']]);

    // Verify the pipeline is live on a route that is clean at level 0.
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--path' => 'lint-fixtures/clean*',
        '--format' => 'json',
    ])->assertExitCode(0);

    // ResponseEmptyController exits 0 once response.empty is disabled.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => 'lint-fixtures/response-empty',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('severity_overrides remaps a finding level so it is excluded at the original threshold', function (): void {
    // ResponseEmptyController emits only response.empty at level 2. Remapping it
    // to level 4 means it no longer appears at --level 2 — exit 0.
    config(['openapi.lint.severity_overrides' => ['response.empty' => 4]]);

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
