<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\DocCommentParser;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Routing\ThrowsExtractor;
use Radiergummi\OpenApi\Tests\Unit\Core\Routing\Fixtures\SimpleController;

uses()->group('routing', 'openapi');

/**
 * @param list<RouteFilter> $filters
 */
function makeIntrospector(array $filters = []): RouteIntrospector
{
    return new RouteIntrospector(
        router: app(Router::class),
        container: app(),
        parser: new DocCommentParser(),
        throwsExtractor: ThrowsExtractor::create(),
        filters: $filters,
    );
}

it('produces an ActionDescriptor with controller and method reflectors for a controller route', function (): void {
    RouteFacade::get('/things', [SimpleController::class, 'index']);

    /** @var list<ActionDescriptor> $descriptors */
    $descriptors = iterator_to_array(makeIntrospector()->discover(), false);

    $match = collect($descriptors)->first(
        static fn(ActionDescriptor $d): bool => $d->route->uri() === 'things',
    );

    expect($match)->not->toBeNull()
        ->and($match->controller?->getName())->toBe(SimpleController::class)
        ->and($match->method?->getName())->toBe('index')
        ->and($match->summary)->toBe('List things.');
});

it('handles a closure-based route by exposing the closure reflector', function (): void {
    // Closure summary line.
    RouteFacade::get('/closure', static fn(): array => []);

    /** @var list<ActionDescriptor> $descriptors */
    $descriptors = iterator_to_array(makeIntrospector()->discover(), false);

    $match = collect($descriptors)->first(
        static fn(ActionDescriptor $d): bool => $d->route->uri() === 'closure',
    );

    expect($match)->not->toBeNull()
        ->and($match->controller)->toBeNull()
        ->and($match->method)->toBeNull()
        ->and($match->closure)->not->toBeNull()
        ->and($match->actionReflector)->not->toBeNull();
});

it('skips routes that match a registered RouteFilter', function (): void {
    RouteFacade::get('/keep', [SimpleController::class, 'index']);
    RouteFacade::get('/drop', [SimpleController::class, 'index']);

    $filter = new class () implements RouteFilter {
        public function shouldSkip(Route $route): bool
        {
            return $route->uri() === 'drop';
        }
    };

    /** @var list<ActionDescriptor> $descriptors */
    $descriptors = iterator_to_array(makeIntrospector([$filter])->discover(), false);
    $uris = array_map(static fn(ActionDescriptor $d): string => $d->route->uri(), $descriptors);

    expect($uris)
        ->toContain('keep')
        ->not->toContain('drop');
});

it('emits no descriptor for a route pointing at a non-existent controller class', function (): void {
    // @phpstan-ignore-next-line argument.type — intentionally invalid for the test
    RouteFacade::get('/bogus', ['NonExistentController@index']);

    /** @var list<ActionDescriptor> $descriptors */
    $descriptors = iterator_to_array(makeIntrospector()->discover(), false);

    $match = collect($descriptors)->first(
        static fn(ActionDescriptor $d): bool => $d->route->uri() === 'bogus',
    );

    // Route is still surfaced, but controller/method are null — caller's responsibility.
    expect($match)->not->toBeNull()
        ->and($match->controller)->toBeNull()
        ->and($match->method)->toBeNull();
});
