<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Lint;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\TransitiveThrowsController;

uses()->group('openapi', 'lint');

/**
 * @return list<Finding>
 */
function throwsTransitiveFindings(string $uri): array
{
    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['throws.transitive-missing'],
        uriGlob: $uri,
    ));

    return $result->findings;
}

it('flags a controller method missing a transitive @throws, naming the exception', function (): void {
    Route::get('tt/missing', [TransitiveThrowsController::class, 'missingThrows'])->name('tt.missing');
    app()->forgetScopedInstances();

    $findings = throwsTransitiveFindings('tt/missing');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('throws.transitive-missing')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->location->routeUri)->toBe('tt/missing')
        ->and($findings[0]->message)->toContain('FakeAction')
        ->and($findings[0]->message)->toContain('RuntimeException')
        ->and($findings[0]->message)->toContain('missingThrows')
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)
        ->toBe(TransitiveThrowsController::class);
});

it('does not fire when the controller redeclares the transitive @throws', function (): void {
    Route::get('tt/with', [TransitiveThrowsController::class, 'withThrows'])->name('tt.with');
    app()->forgetScopedInstances();

    expect(throwsTransitiveFindings('tt/with'))->toBe([]);
});

it('does not fire when the method type-hints no Action', function (): void {
    Route::get('tt/none', [TransitiveThrowsController::class, 'noAction'])->name('tt.none');
    app()->forgetScopedInstances();

    expect(throwsTransitiveFindings('tt/none'))->toBe([]);
});
