<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionMethodController;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    // Mirrors an over-registered resourceful route: the controller has `index` but not `destroy`.
    Route::get('lint-fixtures/action-method/present', [ActionMethodController::class, 'index'])
        ->name('lint.action-method.present');
    Route::delete('lint-fixtures/action-method/missing', [ActionMethodController::class, 'destroy'])
        ->name('lint.action-method.missing');
});

it('flags a route whose controller method does not exist', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'operation.action-method-missing',
        '--uri' => 'lint-fixtures/action-method/missing',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('does not flag a route whose controller method exists', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'operation.action-method-missing',
        '--uri' => 'lint-fixtures/action-method/present',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('does not draw tree-walk findings onto the dead route', function (): void {
    // The route is intentionally excluded from the tree-walk scope: the generator emits no
    // operation for a missing-method route, so tree-walk rules (e.g. response.no-error) must not
    // fire on it. Linting the missing route at the default level must surface exactly this rule.
    $result = app(LintRunner::class)->run(new LintOptions(
        level: 1,
        uriGlob: 'lint-fixtures/action-method/missing',
    ));

    $ruleIds = array_values(array_map(
        static fn($finding): string => $finding->ruleId,
        $result->findings,
    ));

    expect($ruleIds)->toBe(['operation.action-method-missing']);
});
