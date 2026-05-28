<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Radiergummi\OpenApi\Core\Inference\MiddlewareErrorContributor;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

// region Empty middleware

it('returns an empty list when there is no middleware', function (): void {
    $contributor = new MiddlewareErrorContributor(middlewareMap: [
        'auth'     => ['status' => 401, 'description' => 'Unauthenticated', 'exception' => AuthenticationException::class],
        'scope'    => ['status' => 403, 'description' => 'Insufficient scope'],
        'throttle' => ['status' => 429, 'description' => 'Too many requests', 'exception' => ThrottleRequestsException::class],
    ]);

    $result = $contributor->contribute(ActionDescriptorFactory::withMiddleware([]));

    expect($result)->toBe([]);
});

// endregion

// region auth middleware

it('returns a 401 descriptor when auth middleware is present and auth is configured', function (): void {
    $contributor = new MiddlewareErrorContributor(middlewareMap: [
        'auth' => ['status' => 401, 'description' => 'Unauthenticated', 'exception' => AuthenticationException::class],
    ]);

    $result = $contributor->contribute(ActionDescriptorFactory::withMiddleware(['auth']));

    expect($result)->toHaveCount(1);
    expect($result[0]->status)->toBe(401);
    expect($result[0]->description)->toBe('Unauthenticated');
    expect($result[0]->exceptionClass)->toBe(AuthenticationException::class);
});

// endregion

// region scope middleware

it('returns a 403 descriptor when scope middleware is present and scope is configured', function (): void {
    $contributor = new MiddlewareErrorContributor(middlewareMap: [
        'scope' => ['status' => 403, 'description' => 'Insufficient scope'],
    ]);

    $result = $contributor->contribute(ActionDescriptorFactory::withMiddleware(['scope:read']));

    expect($result)->toHaveCount(1);
    expect($result[0]->status)->toBe(403);
    expect($result[0]->description)->toBe('Insufficient scope');
    expect($result[0]->exceptionClass)->toBeNull();
});

// endregion

// region throttle middleware

it('returns a 429 descriptor when throttle middleware is present and throttle is configured', function (): void {
    $contributor = new MiddlewareErrorContributor(middlewareMap: [
        'throttle' => ['status' => 429, 'description' => 'Too many requests', 'exception' => ThrottleRequestsException::class],
    ]);

    $result = $contributor->contribute(ActionDescriptorFactory::withMiddleware(['throttle:60,1']));

    expect($result)->toHaveCount(1);
    expect($result[0]->status)->toBe(429);
    expect($result[0]->description)->toBe('Too many requests');
    expect($result[0]->exceptionClass)->toBe(ThrottleRequestsException::class);
});

// endregion

// region all three middleware

it('returns three descriptors in auth/scope/throttle order when all three middleware are present', function (): void {
    $contributor = new MiddlewareErrorContributor(middlewareMap: [
        'auth'     => ['status' => 401, 'description' => 'Unauthenticated', 'exception' => AuthenticationException::class],
        'scope'    => ['status' => 403, 'description' => 'Insufficient scope'],
        'throttle' => ['status' => 429, 'description' => 'Too many requests', 'exception' => ThrottleRequestsException::class],
    ]);

    $result = $contributor->contribute(ActionDescriptorFactory::withMiddleware(['auth', 'scope:read', 'throttle:60,1']));

    expect($result)->toHaveCount(3);
    expect($result[0]->status)->toBe(401);
    expect($result[1]->status)->toBe(403);
    expect($result[2]->status)->toBe(429);
});

// endregion

// region middleware present but kind absent from config

it('emits no descriptor for a middleware kind absent from the config map', function (): void {
    $contributor = new MiddlewareErrorContributor(middlewareMap: [
        'auth' => ['status' => 401, 'description' => 'Unauthenticated'],
        // 'throttle' intentionally absent
    ]);

    $result = $contributor->contribute(ActionDescriptorFactory::withMiddleware(['auth', 'throttle:60,1']));

    expect($result)->toHaveCount(1);
    expect($result[0]->status)->toBe(401);
});

// endregion
