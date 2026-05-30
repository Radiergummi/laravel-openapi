<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Testing\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Fixtures\RouteBoundFixtureController;

uses()->group('openapi');

it('builds an ActionDescriptor with sensible defaults from a controller and method name', function (): void {
    $descriptor = ActionDescriptorFactory::make(
        controller: RouteBoundFixtureController::class,
        method: 'callback',
    );

    expect($descriptor)->toBeInstanceOf(ActionDescriptor::class)
        ->and($descriptor->controller?->getName())->toBe(RouteBoundFixtureController::class)
        ->and($descriptor->method?->getName())->toBe('callback')
        ->and($descriptor->summary)->toBeNull()
        ->and($descriptor->description)->toBeNull()
        ->and($descriptor->throws)->toBe([]);
});

it('threads through a route with the supplied URI and methods', function (): void {
    $descriptor = ActionDescriptorFactory::make(
        controller: RouteBoundFixtureController::class,
        method: 'callback',
        uri: '/widgets/{id}',
        httpMethods: ['POST', 'PUT'],
    );

    expect($descriptor->route->uri())->toBe('widgets/{id}')
        ->and($descriptor->route->methods())->toContain('POST')
        ->and($descriptor->route->methods())->toContain('PUT');
});

it('accepts summary, description, and throws overrides', function (): void {
    $descriptor = ActionDescriptorFactory::make(
        controller: RouteBoundFixtureController::class,
        method: 'callback',
        summary: 'Trigger the callback',
        description: 'Long-form description of the callback action.',
        throws: [RuntimeException::class, LogicException::class],
    );

    expect($descriptor->summary)->toBe('Trigger the callback')
        ->and($descriptor->description)->toBe('Long-form description of the callback action.')
        ->and($descriptor->throws)->toBe([RuntimeException::class, LogicException::class]);
});
