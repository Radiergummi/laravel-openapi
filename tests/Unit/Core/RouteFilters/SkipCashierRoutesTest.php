<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipCashierRoutes;

uses()->group('routing', 'openapi');

beforeEach(function (): void {
    $this->filter = new SkipCashierRoutes();
});

function makeCashierRoute(?string $name): Route
{
    $route = new Route(['GET'], '/example', static fn() => null);

    if ($name !== null) {
        $route->name($name);
    }

    return $route;
}

it('skips routes named under the cashier. prefix', function (): void {
    expect($this->filter->shouldSkip(makeCashierRoute('cashier.webhook')))->toBeTrue()
        ->and($this->filter->shouldSkip(makeCashierRoute('cashier.payment')))->toBeTrue();
});

it('keeps routes whose name does not start with cashier.', function (): void {
    expect($this->filter->shouldSkip(makeCashierRoute('flights.index')))->toBeFalse()
        ->and($this->filter->shouldSkip(makeCashierRoute('bookings.show')))->toBeFalse()
        ->and($this->filter->shouldSkip(makeCashierRoute('api.cashier')))->toBeFalse()
        ->and($this->filter->shouldSkip(makeCashierRoute('cashierish.thing')))->toBeFalse();
});

it('tolerates Cashier being absent by leaving unnamed routes alone', function (): void {
    expect($this->filter->shouldSkip(makeCashierRoute(null)))->toBeFalse();
});
