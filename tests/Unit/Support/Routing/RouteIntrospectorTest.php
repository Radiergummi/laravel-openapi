<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\DocCommentParser;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Support\Routing\ThrowsExtractor;

uses()->group('routing', 'openapi');

// Controller resolution, closure routes, and filter integration are exercised
// end-to-end by feature tests (ClosureRouteAttributesTest, SkipPassportRoutesTest,
// every controller-based generation test). The only case worth a dedicated unit
// is the defensive non-existent controller path, which feature tests cannot trigger
// because Laravel rejects the route before it reaches the introspector.

it('emits an ActionDescriptor with null controller/method when the route points at a non-existent class', function (): void {
    RouteFacade::get('/bogus', ['NonExistentController@index']);

    $introspector = new RouteIntrospector(
        router: app(Router::class),
        container: app(),
        parser: new DocCommentParser(),
        throwsExtractor: ThrowsExtractor::create(),
    );

    /** @var list<ActionDescriptor> $descriptors */
    $descriptors = iterator_to_array($introspector->discover(), false);

    $match = collect($descriptors)->first(
        static fn(ActionDescriptor $d): bool => $d->route->uri() === 'bogus',
    );

    expect($match)->not->toBeNull()
        ->and($match->controller)->toBeNull()
        ->and($match->method)->toBeNull();
});
