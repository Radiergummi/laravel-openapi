<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Events\LintFindingEmitted;
use Radiergummi\OpenApi\Core\Events\RouteSkipped;
use Radiergummi\OpenApi\Core\Events\SpecGenerationCompleted;
use Radiergummi\OpenApi\Core\Events\SpecGenerationStarted;
use Radiergummi\OpenApi\Core\Inclusion\SkipReason;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Tests\Fixtures\AuthoringFixtureController;

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

    Event::listen(SpecGenerationStarted::class, static function (SpecGenerationStarted $e) use (&$started): void {
        $started[] = $e;
    });
    Event::listen(SpecGenerationCompleted::class, static function (SpecGenerationCompleted $e) use (&$completed): void {
        $completed[] = $e;
    });

    generateSpec();

    expect($started)->toHaveCount(1)
        ->and($started[0]->spec)->toBe('default');

    expect($completed)->toHaveCount(1)
        ->and($completed[0]->spec)->toBe('default')
        ->and($completed[0]->durationMs)->toBeGreaterThanOrEqual(0.0)
        ->and($completed[0]->document->paths)->toBeArray()->not->toBeEmpty();
});

it('dispatches RouteSkipped with Visibility reason for #[Hide]', function (): void {
    /** @var list<RouteSkipped> $captured */
    $captured = captureEvents(RouteSkipped::class, static fn() => generateSpec());

    $hidden = array_values(array_filter(
        $captured,
        static fn(RouteSkipped $e): bool => str_contains($e->route->uri(), 'oa-events/hidden'),
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
        static fn(RouteSkipped $e): bool => str_contains($e->route->uri(), 'oa-events/filtered'),
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
        static fn(RouteSkipped $e): bool => str_contains($e->route->uri(), 'oa-events/v2-only'),
    ));

    expect($skipped)->toHaveCount(1)
        ->and($skipped[0]->spec)->toBe('v2')
        ->and($skipped[0]->reason)->toBe(SkipReason::SpecMembership);
});

it('dispatches LintFindingEmitted whenever a finding is collected', function (): void {
    /** @var list<LintFindingEmitted> $captured */
    $captured = [];

    Event::listen(LintFindingEmitted::class, static function (LintFindingEmitted $e) use (&$captured): void {
        $captured[] = $e;
    });

    /** @var FindingsCollector $collector */
    $collector = app(FindingsCollector::class);

    $collector->emit(new Finding(
        ruleId: 'test.example',
        level: 1,
        message: 'a synthetic finding',
    ));

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->finding->ruleId)->toBe('test.example');
});

it('dispatches LintFindingEmitted for findings emitted during a lint run', function (): void {
    Route::get('lint-runner/broken', [\Radiergummi\OpenApi\Tests\Fixtures\Lint\BrokenController::class, 'stream'])
        ->name('runner.broken');

    /** @var list<LintFindingEmitted> $captured */
    $captured = captureEvents(LintFindingEmitted::class, static function (): void {
        app(\Radiergummi\OpenApi\Core\Lint\LintRunner::class)->run(new \Radiergummi\OpenApi\Core\Lint\LintOptions(
            level: 2,
            path: 'lint-runner/broken*',
        ));
    });

    expect($captured)->not->toBeEmpty();
});
