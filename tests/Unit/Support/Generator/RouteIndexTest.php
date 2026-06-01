<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Generator\RouteIndex;

uses()->group('openapi');

it('records and looks up a route name by method and uri', function (): void {
    $index = new RouteIndex();
    $index->record('users/{user}', 'GET', 'users.show');

    expect($index->routeNameFor('users/{user}', 'GET'))->toBe('users.show');
});

it('normalises leading slash and method case on lookup', function (): void {
    $index = new RouteIndex();
    $index->record('users/{user}', 'get', 'users.show');

    // PathsStage records the raw route uri; OverridesStage looks up with the
    // leading-slash $pathItem->path and an uppercase verb. Both must agree.
    expect($index->routeNameFor('/users/{user}', 'GET'))->toBe('users.show');
});

it('returns null for an unknown method/uri pair', function (): void {
    $index = new RouteIndex();
    $index->record('users/{user}', 'GET', 'users.show');

    expect($index->routeNameFor('users/{user}', 'POST'))->toBeNull()
        ->and($index->routeNameFor('posts', 'GET'))->toBeNull();
});

it('records a null route name for unnamed routes', function (): void {
    $index = new RouteIndex();
    $index->record('health', 'GET', null);

    // Distinguish "recorded, unnamed" from "never recorded" via has().
    expect($index->routeNameFor('health', 'GET'))->toBeNull()
        ->and($index->has('health', 'GET'))->toBeTrue()
        ->and($index->has('missing', 'GET'))->toBeFalse();
});

it('overwrites a previously recorded entry for the same key', function (): void {
    $index = new RouteIndex();
    $index->record('users', 'GET', 'users.old');
    $index->record('users', 'GET', 'users.new');

    expect($index->routeNameFor('users', 'GET'))->toBe('users.new');
});
