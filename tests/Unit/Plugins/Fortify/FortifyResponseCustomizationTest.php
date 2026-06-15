<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Laravel\Fortify\Contracts\LoginResponse;
use Radiergummi\OpenApi\Plugins\Fortify\Support\FortifyResponseCustomization;

uses()->group('openapi', 'plugin:fortify');

it('reports stock when the contract resolves to a Fortify class', function (): void {
    $container = new Container();
    $container->singleton(LoginResponse::class, Laravel\Fortify\Http\Responses\LoginResponse::class);

    $gate = new FortifyResponseCustomization($container);

    expect($gate->isStock(LoginResponse::class))->toBeTrue();
});

it('reports customized when rebound to a non-Fortify class via a closure', function (): void {
    $container = new Container();
    $container->bind(LoginResponse::class, fn (): LoginResponse => new class () implements LoginResponse {
        public function toResponse($request)
        {
            return response()->noContent();
        }
    });

    $gate = new FortifyResponseCustomization($container);

    expect($gate->isStock(LoginResponse::class))->toBeFalse();
});

it('reports stock for a null contract (no contract governs the body)', function (): void {
    $gate = new FortifyResponseCustomization(new Container());

    expect($gate->isStock(null))->toBeTrue();
});

it('reports customized when the contract is unbound', function (): void {
    $gate = new FortifyResponseCustomization(new Container());

    expect($gate->isStock(LoginResponse::class))->toBeFalse();
});
