<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OperationIdDeriver;

uses()->group('openapi', 'generator');

function deriverDescriptor(Route $route): ActionDescriptor
{
    return new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
    );
}

it('uses the route name under the default route-name strategy', function (): void {
    $route = new Route(['GET'], '/users', [])->name('users.index');

    $operationId = new OperationIdDeriver()->derive(deriverDescriptor($route), HttpMethod::Get);

    expect($operationId)->toBe('users.index');
});

it('suffixes the route name with the verb for a multi-method route', function (): void {
    $route = new Route(['GET', 'POST'], '/users', [])->name('users');

    $operationId = new OperationIdDeriver()->derive(deriverDescriptor($route), HttpMethod::Post);

    expect($operationId)->toBe('users.post');
});

it('falls back to {method}_{path} for an unnamed route under route-name', function (): void {
    $route = new Route(['GET'], '/users/{user}', []);

    $operationId = new OperationIdDeriver()->derive(deriverDescriptor($route), HttpMethod::Get);

    expect($operationId)->toBe('get_users_user_');
});

it('falls back to {method}_{path} for a generated route name', function (): void {
    $route = new Route(['GET'], '/users', [])->name('generated::abc123');

    $operationId = new OperationIdDeriver()->derive(deriverDescriptor($route), HttpMethod::Get);

    expect($operationId)->toBe('get_users');
});

it('always uses {method}_{path} under the method-path strategy', function (): void {
    config()->set('openapi.operation_id_strategy', 'method-path');

    $route = new Route(['POST'], '/users', [])->name('users.store');

    $operationId = new OperationIdDeriver()->derive(deriverDescriptor($route), HttpMethod::Post);

    expect($operationId)->toBe('post_users');
});

it('sanitises spaces and illegal characters to underscores', function (): void {
    $sanitised = new OperationIdDeriver()->sanitise('Get Users! (all)');

    expect($sanitised)->toBe('Get_Users_all_');
});

it('strips leading non-letters while preserving dots', function (): void {
    $sanitised = new OperationIdDeriver()->sanitise('123.users.index');

    expect($sanitised)->toBe('users.index');
});

it('preserves an already-valid operationId', function (): void {
    $sanitised = new OperationIdDeriver()->sanitise('users.index');

    expect($sanitised)->toBe('users.index');
});
