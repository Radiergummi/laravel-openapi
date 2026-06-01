<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\RouteFilters\SkipPassportRoutes;

uses()->group('routing', 'openapi');

beforeEach(function (): void {
    $this->filter = new SkipPassportRoutes();
});

function makeRoute(?string $name): Route
{
    $route = new Route(['GET'], '/example', static fn() => null);

    if ($name !== null) {
        $route->name($name);
    }

    return $route;
}

it('skips routes named under the passport. prefix', function (): void {
    expect($this->filter->shouldSkip(makeRoute('passport.tokens.index')))->toBeTrue()
        ->and($this->filter->shouldSkip(makeRoute('passport.clients.store')))->toBeTrue()
        ->and($this->filter->shouldSkip(makeRoute('passport.authorizations.approve')))->toBeTrue();
});

it('keeps routes whose name does not start with passport.', function (): void {
    expect($this->filter->shouldSkip(makeRoute('flights.index')))->toBeFalse()
        ->and($this->filter->shouldSkip(makeRoute('bookings.show')))->toBeFalse()
        ->and($this->filter->shouldSkip(makeRoute('api.passport')))->toBeFalse()
        ->and($this->filter->shouldSkip(makeRoute('passportish.thing')))->toBeFalse();
});

it('tolerates Passport being absent by leaving unnamed routes alone', function (): void {
    expect($this->filter->shouldSkip(makeRoute(null)))->toBeFalse();
});
