<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Lint;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\IgnoreLint;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ReturnTypeNudgeController;

uses()->group('openapi', 'lint');

/**
 * A controller whose return type is unusable, but whose body a Tier-1 resolver reads into a
 * response — the generation-time emission must not fire (a schema was produced).
 */
class InlineResponseReturnTypeController
{
    public function inlineJson(): mixed
    {
        return response()->json(['ok' => true]);
    }
}

#[IgnoreLint('operation.return-type-missing', reason: 'intentional')]
class SuppressedReturnTypeController
{
    public function untyped(): mixed
    {
        return null;
    }
}

/**
 * @return list<Finding>
 */
function returnTypeMissingFindings(string $uri): array
{
    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['operation.return-type-missing'],
        uriGlob: $uri,
    ));

    return $result->findings;
}

it('flags an untyped action at generation time, with a reason', function (): void {
    Route::get('rt/untyped', [ReturnTypeNudgeController::class, 'untyped'])->name('rt.untyped');
    app()->forgetScopedInstances();

    $findings = returnTypeMissingFindings('rt/untyped');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.return-type-missing')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->location->routeUri)->toBe('rt/untyped')
        ->and($findings[0]->message)->toContain('ReturnTypeNudgeController')
        ->and($findings[0]->message)->toContain('untyped')
        ->and($findings[0]->message)->toContain('has no return type');
});

it('distinguishes the mixed/void/never reason', function (string $method, string $uri, string $reason): void {
    Route::get($uri, [ReturnTypeNudgeController::class, $method])->name("rt.{$method}");
    app()->forgetScopedInstances();

    $findings = returnTypeMissingFindings($uri);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain($reason);
})->with([
    'mixed' => ['mixedReturn', 'rt/mixed', '`mixed` return type'],
    'void' => ['voidReturn', 'rt/void', '`void` return type'],
]);

it('stamps the controller class so #[IgnoreLint] can suppress it', function (): void {
    Route::get('rt/untyped', [ReturnTypeNudgeController::class, 'untyped'])->name('rt.untyped');
    app()->forgetScopedInstances();

    $findings = returnTypeMissingFindings('rt/untyped');

    expect($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)
        ->toBe(ReturnTypeNudgeController::class);
});

it('does not fire', function (string $method, string $uri): void {
    Route::get($uri, [ReturnTypeNudgeController::class, $method])->name("rt.{$method}");
    app()->forgetScopedInstances();

    expect(returnTypeMissingFindings($uri))->toBe([]);
})->with([
    'typed return value' => ['typedArray', 'rt/typed'],
    'untyped but carries #[Response]' => ['withResponseAttribute', 'rt/response-attr'],
    'untyped but carries #[ResponseResource]' => ['withResponseResourceAttribute', 'rt/resource-attr'],
]);

it('does not fire when a resolver produced a response from the body', function (): void {
    Route::get('rt/inline-json', [InlineResponseReturnTypeController::class, 'inlineJson'])
        ->name('rt.inline-json');
    app()->forgetScopedInstances();

    expect(returnTypeMissingFindings('rt/inline-json'))->toBe([]);
});

it('is suppressed by #[IgnoreLint] on the controller class', function (): void {
    Route::get('rt/suppressed', [SuppressedReturnTypeController::class, 'untyped'])->name('rt.suppressed');
    app()->forgetScopedInstances();

    expect(returnTypeMissingFindings('rt/suppressed'))->toBe([]);
});
