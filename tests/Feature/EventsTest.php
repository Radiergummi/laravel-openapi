<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;
use Radiergummi\OpenApi\Events\LintFindingEmitted;
use Radiergummi\OpenApi\Events\RouteSkipped;
use Radiergummi\OpenApi\Events\SkipReason;
use Radiergummi\OpenApi\Events\SpecGenerationCompleted;
use Radiergummi\OpenApi\Events\SpecGenerationStarted;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Tests\Fixtures\AuthoringFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\BrokenController;

uses()->group('openapi', 'events');

/**
 * Capture instances of $eventClass dispatched during $callback.
 *
 * Uses Event::listen rather than Event::fake because the generator and collectors gate
 * dispatch on Dispatcher::hasListeners() — EventFake delegates that check to the original
 * dispatcher, which returns false. listen() registers a real listener that satisfies the
 * gate.
 *
 * @template T of object
 *
 * @param class-string<T> $eventClass
 *
 * @return list<T>
 */
function captureEvents(string $eventClass, callable $callback): array
{
    $captured = [];

    Event::listen($eventClass, function (object $event) use (&$captured): void {
        $captured[] = $event;
    });

    $callback();

    return $captured;
}

beforeEach(function (): void {
    Route::get('/oa-events/public', [AuthoringFixtureController::class, 'publicAction']);
    Route::get('/oa-events/hidden', [AuthoringFixtureController::class, 'hiddenAction']);
});

it('dispatches SpecGenerationStarted and SpecGenerationCompleted around generation', function (): void {
    /** @var list<SpecGenerationStarted> $started */
    $started = [];
    /** @var list<SpecGenerationCompleted> $completed */
    $completed = [];

    Event::listen(SpecGenerationStarted::class, static function (SpecGenerationStarted $exception) use (&$started): void {
        $started[] = $exception;
    });
    Event::listen(SpecGenerationCompleted::class, static function (SpecGenerationCompleted $exception) use (&$completed): void {
        $completed[] = $exception;
    });

    generateSpec();

    expect($started)->toHaveCount(1)
        ->and($started[0]->spec)->toBe('default');

    expect($completed)->toHaveCount(1)
        ->and($completed[0]->spec)->toBe('default')
        ->and($completed[0]->durationMs)->toBeGreaterThanOrEqual(0.0);

    // Pin the document the event carries: the visible route is present, the
    // #[Hide]-marked one is absent. A bare ->toBeArray()->not->toBeEmpty()
    // would silently accept an event firing with the wrong document.
    $uris = array_map(static fn(OA\PathItem $p): string => $p->path, $completed[0]->document->paths);
    expect($uris)->toContain('/oa-events/public')
        ->not->toContain('/oa-events/hidden');
});

it('dispatches RouteSkipped with Visibility reason for #[Hide]', function (): void {
    /** @var list<RouteSkipped> $captured */
    $captured = captureEvents(RouteSkipped::class, static fn() => generateSpec());

    $hidden = array_values(array_filter(
        $captured,
        static fn(RouteSkipped $event): bool => str_contains($event->route->uri(), 'oa-events/hidden'),
    ));

    expect($hidden)->toHaveCount(1)
        ->and($hidden[0]->spec)->toBe('default')
        ->and($hidden[0]->reason)->toBe(SkipReason::Visibility)
        ->and($hidden[0]->summary)->toContain('hidden');
});

it('dispatches RouteSkipped with GlobalFilter reason when a route is rejected by a RouteFilter', function (): void {
    Route::get('/oa-events/filtered', [AuthoringFixtureController::class, 'publicAction']);

    config()->set('openapi.filters', [
        new class () implements RouteFilter {
            public function shouldSkip(RoutingRoute $route): bool
            {
                return str_contains($route->uri(), 'oa-events/filtered');
            }
        },
    ]);

    app()->forgetScopedInstances();

    /** @var list<RouteSkipped> $captured */
    $captured = captureEvents(RouteSkipped::class, static fn() => generateSpec());

    $skipped = array_values(array_filter(
        $captured,
        static fn(RouteSkipped $event): bool => str_contains($event->route->uri(), 'oa-events/filtered'),
    ));

    expect($skipped)->toHaveCount(1)
        ->and($skipped[0]->reason)->toBe(SkipReason::GlobalFilter)
        ->and($skipped[0]->summary)->toContain('global filter');
});

it('dispatches RouteSkipped with SpecMembership reason when a route is not in the spec', function (): void {
    Route::get('/oa-events/v2-only', [AuthoringFixtureController::class, 'publicAction']);

    config()->set('openapi.specs', [
        'v2' => [
            'info' => ['title' => 'V2', 'version' => '2.0.0'],
            'match' => ['prefix' => 'never-matches-anything'],
        ],
    ]);

    app()->forgetScopedInstances();

    /** @var list<RouteSkipped> $captured */
    $captured = captureEvents(RouteSkipped::class, static fn() => generateSpec('v2'));

    $skipped = array_values(array_filter(
        $captured,
        static fn(RouteSkipped $event): bool => str_contains($event->route->uri(), 'oa-events/v2-only'),
    ));

    expect($skipped)->toHaveCount(1)
        ->and($skipped[0]->spec)->toBe('v2')
        ->and($skipped[0]->reason)->toBe(SkipReason::SpecMembership);
});

it('dispatches LintFindingEmitted whenever a finding is collected', function (): void {
    /** @var list<LintFindingEmitted> $captured */
    $captured = [];

    Event::listen(LintFindingEmitted::class, static function (LintFindingEmitted $event) use (&$captured): void {
        $captured[] = $event;
    });

    /** @var FindingsCollector $collector */
    $collector = app(FindingsCollector::class);

    $collector->emit(new Finding(
        ruleId: 'test.example',
        severity: Severity::Degraded,
        message: 'a synthetic finding',
    ));

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->finding->ruleId)->toBe('test.example');
});

it('dispatches LintFindingEmitted for findings emitted during a lint run', function (): void {
    Route::get('lint-runner/broken', [BrokenController::class, 'stream'])
        ->name('runner.broken');

    /** @var list<LintFindingEmitted> $captured */
    $captured = captureEvents(LintFindingEmitted::class, static function (): void {
        app(LintRunner::class)->run(new LintOptions(
            level: 2,
            uriGlob: 'lint-runner/broken*',
        ));
    });

    expect($captured)->not->toBeEmpty();
});
