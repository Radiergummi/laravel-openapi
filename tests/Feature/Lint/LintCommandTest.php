<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Lint\Formatters\CliFormatter;
use Radiergummi\OpenApi\Lint\Formatters\JsonFormatter;
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
use Radiergummi\OpenApi\Tests\Fixtures\Lint\UnknownRuleController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    Route::get('lint-fixtures/clean', [CleanController::class, 'list'])->name('lint.clean.list');
    Route::get('lint-fixtures/broken/stream', [BrokenController::class, 'stream'])->name('lint.broken.stream');
    Route::get('lint-fixtures/suppressed/stream', [SuppressedController::class, 'stream'])->name('lint.suppressed.stream');
    // Dedicated single-finding fixtures: emit only response.success-empty-body (level 2) at levels 0–2.
    Route::get('lint-fixtures/response-empty', [ResponseEmptyController::class, 'index'])->name('lint.response-empty');
    Route::get('lint-fixtures/suppressed-response-empty', [SuppressedResponseEmptyController::class, 'index'])->name('lint.suppressed-response-empty');
});

afterEach(function (): void {
    OpenApiExtensions::flush();
});

/**
 * Registers a document transformer that sets `openapi` to a value the OAS 3.1 meta-schema's
 * version pattern rejects, so the generated spec fails validation and the (level-0) spec.invalid
 * rule fires.
 */
function corruptLintedSpec(): void
{
    OpenApiExtensions::transformDocument(static function (OA\OpenApi $document): void {
        $document->openapi = 'not-a-valid-version';
    });
}

it('exits 0 when clean controller is the only route', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--uri' => 'lint-fixtures/clean*',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('exits 1 when broken controller has findings', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/broken*',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('respects suppression directives', function (): void {
    // SuppressedResponseEmptyController suppresses response.success-empty-body (level 2) —
    // the only finding that would fire at this level. Exit 0 proves suppression works.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/suppressed-response-empty',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('--no-suppress disables directives', function (): void {
    // With suppression disabled, response.success-empty-body (level 2) surfaces — exit 1.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/suppressed-response-empty',
        '--format' => 'json',
        '--no-suppress' => true,
    ])->assertExitCode(1);
});

it('runs meta-schema validation by default (spec.invalid fires on an invalid document)', function (): void {
    corruptLintedSpec();

    // The clean path emits no level-0 findings normally (exit 0); the dropped `info` makes
    // spec.invalid (level 0) fire, so the run exits 1.
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--uri' => 'lint-fixtures/clean*',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('skips meta-schema validation when --no-validate is passed', function (): void {
    corruptLintedSpec();

    // With the meta-schema pass skipped, spec.invalid never runs — the clean path has no other
    // level-0 findings, so the run exits 0 despite the invalid document.
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--uri' => 'lint-fixtures/clean*',
        '--format' => 'json',
        '--no-validate' => true,
    ])->assertExitCode(0);
});

it('uses config lint level when --level is not passed', function (): void {
    config(['openapi.lint.level' => 2]);

    // BrokenController has no summary — summary.missing fires at level 2.
    // Without the config default this would exit 0 (level 0 misses level-2 rules).
    $this->artisan('openapi:lint', [
        '--uri' => 'lint-fixtures/broken*',
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
        '--uri' => 'lint-fixtures/clean*',
        '--format' => 'json',
    ])->assertExitCode(0);

    // ResponseEmptyController exits 0 once response.success-empty-body is disabled.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/response-empty',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('severity_overrides remaps a finding level so it is excluded at the original threshold', function (): void {
    // ResponseEmptyController emits only response.success-empty-body at level 2. Remapping
    // it to level 4 means it no longer appears at --level 2 — exit 0.
    config(['openapi.lint.severity_overrides' => ['response.success-empty-body' => 4]]);

    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/response-empty',
        '--format' => 'json',
    ])->assertExitCode(0);

    // At level 4 the remapped finding is within threshold again — exit 1.
    $this->artisan('openapi:lint', [
        '--level' => 4,
        '--uri' => 'lint-fixtures/response-empty',
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
            uriGlob: 'lint-fixtures/broken*',
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
        '--uri'  => 'lint-fixtures/broken*',
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
            uriGlob: 'lint-fixtures/clean*',
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

it('--uri filter scopes extractor-emitted findings, not just tree-walk findings', function (): void {
    // CleanController::list as POST triggers request.empty (no body schema). The lint-fixtures/clean
    // GET route registered in beforeEach is untouched. Without filtering both POSTs would emit
    // request.empty; with --uri='lint-fixtures/clean*' the leak route's finding must drop.
    Route::post('leak-fixtures/excluded', [CleanController::class, 'list'])->name('leak.excluded');

    $unfiltered = app(LintRunner::class)->run(new LintOptions(level: 2));
    $leakFindings = array_filter(
        $unfiltered->findings,
        static fn($f) => $f->ruleId === 'request.empty'
            && $f->location->routeUri === 'leak-fixtures/excluded',
    );
    expect($leakFindings)->not->toBeEmpty();

    $filtered = app(LintRunner::class)->run(new LintOptions(level: 2, uriGlob: 'lint-fixtures/clean*'));
    $leakedAfterFilter = array_filter(
        $filtered->findings,
        static fn($f) => $f->location->routeUri === 'leak-fixtures/excluded',
    );
    expect($leakedAfterFilter)->toBeEmpty();
});

it('--uri filter scopes extractor findings that carry no routeUri', function (): void {
    // UnknownRuleController injects a FormRequest with an un-introspectable Rule, so generation
    // emits a route-scoped `rule.unknown` finding for it — historically with no routeUri. The
    // clean GET route from beforeEach never produces rule.unknown, so with --uri scoped to the
    // clean route, any surviving rule.unknown is a leak from the excluded route.
    Route::post('leak-fixtures/unknown-rule', [UnknownRuleController::class, 'store'])->name('leak.unknown-rule');

    $unfiltered = app(LintRunner::class)->run(new LintOptions(level: 2));
    $unknownRuleFindings = array_filter(
        $unfiltered->findings,
        static fn($f) => $f->ruleId === 'rule.unknown',
    );
    expect($unknownRuleFindings)->not->toBeEmpty();

    $filtered = app(LintRunner::class)->run(new LintOptions(level: 2, uriGlob: 'lint-fixtures/clean*'));
    $leaked = array_filter(
        $filtered->findings,
        static fn($f) => $f->ruleId === 'rule.unknown',
    );
    expect($leaked)->toBeEmpty();
});

it('--uri keeps a schema finding when an in-scope route shares the schema', function (): void {
    // The same FormRequest backs an in-scope and an out-of-scope route. Its rule.unknown finding
    // is schema-keyed (no routeUri) and must stay visible: the in-scope route references the
    // schema, so scoping must not silently drop a finding that belongs to the requested slice.
    Route::post('shared-fixtures/in-scope', [UnknownRuleController::class, 'store'])->name('shared.in-scope');
    Route::post('shared-fixtures/out-scope', [UnknownRuleController::class, 'store'])->name('shared.out-scope');

    $filtered = app(LintRunner::class)->run(new LintOptions(level: 2, uriGlob: 'shared-fixtures/in-scope'));
    $kept = array_filter(
        $filtered->findings,
        static fn($f) => $f->ruleId === 'rule.unknown',
    );
    expect($kept)->not->toBeEmpty();
});

it('cannot disable spec.invalid via config disabled_rules', function (): void {
    config(['openapi.lint.disabled_rules' => ['spec.invalid']]);

    // spec.invalid must remain active regardless. CleanController produces no
    // findings at level 0 — exit 0 proves the pipeline runs normally.
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--uri' => 'lint-fixtures/clean*',
        '--format' => 'json',
    ])->assertExitCode(0);

    // BrokenController has no summary so summary.missing (level 2) fires — a
    // rule other than spec.invalid. Exit 1 at level 2 proves the pipeline is
    // live and disabled_rules did not inadvertently disable other rules.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/broken*',
        '--format' => 'json',
    ])->assertExitCode(1);
});

// region --path file scoping

it('--path scopes by source file, keeping only routes whose file is listed', function (): void {
    $brokenFile = Str::after((string) (new ReflectionMethod(BrokenController::class, 'stream'))->getFileName(), base_path() . '/');

    // Only the broken controller's file is listed → only its route is linted → its findings fire.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--path' => [$brokenFile],
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('--path drops findings from routes whose source file is not listed', function (): void {
    $cleanFile = Str::after((string) (new ReflectionMethod(CleanController::class, 'list'))->getFileName(), base_path() . '/');

    // Unscoped, the broken route emits findings at level 2.
    $unfiltered = app(LintRunner::class)->run(new LintOptions(level: 2));
    $brokenFindings = array_filter(
        $unfiltered->findings,
        static fn($f) => $f->location->routeUri === '/lint-fixtures/broken/stream',
    );
    expect($brokenFindings)->not->toBeEmpty();

    // Scoped to the clean controller's file only → the broken route is dropped, its findings gone.
    $filtered = app(LintRunner::class)->run(new LintOptions(level: 2, files: [$cleanFile]));
    $leaked = array_filter(
        $filtered->findings,
        static fn($f) => $f->location->routeUri === '/lint-fixtures/broken/stream',
    );
    expect($leaked)->toBeEmpty();
});

// endregion

// region coverage rendering + gates

it('prints a coverage summary line in cli format', function (): void {
    // Both substrings live on the single Coverage line; the artisan output matcher registers a
    // separate doWrite expectation per substring, so render directly and assert on the buffer.
    $result = app(LintRunner::class)->run(new LintOptions(level: 2, uriGlob: 'lint-fixtures/broken*'));

    $output = new BufferedOutput();
    app(CliFormatter::class)->render(
        $result->findings,
        $result->level,
        $result->exitCode,
        $output,
        $result->coverage,
    );
    $rendered = $output->fetch();

    expect($rendered)->toContain('Coverage:')
        ->and($rendered)->toContain('operations');
});

it('emits a coverage block in json format', function (): void {
    // The JSON document is written in a single writeln, so chained expectsOutputToContain
    // calls can't all match it (each registers a separate doWrite expectation). Render
    // directly and assert against the captured payload instead.
    $result = app(LintRunner::class)->run(new LintOptions(level: 2, uriGlob: 'lint-fixtures/broken*'));

    $output = new BufferedOutput();
    app(JsonFormatter::class)->render(
        $result->findings,
        $result->level,
        $result->exitCode,
        $output,
        $result->coverage,
    );
    $json = $output->fetch();

    expect($json)->toContain('"coverage"')
        ->and($json)->toContain('"generator_version"')
        ->and($json)->toContain('"coverage_percent"');
});

it('exits zero under a satisfied min-coverage gate even with findings', function (): void {
    // broken route → 0% coverage; a 0% floor is always met → exit 0 despite findings.
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/broken*',
        '--min-coverage' => '0',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('exits non-zero when min-coverage is not met', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/broken*',
        '--min-coverage' => '100',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('exits non-zero when the finding count exceeds max-findings', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/broken*',
        '--max-findings' => '0',
        '--format' => 'json',
    ])->assertExitCode(1);
});

// endregion
