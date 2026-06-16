<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\TagDeriver;
use Radiergummi\OpenApi\Tests\Fixtures\Auth\AuthFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

it('derives a pluralised tag from the controller short name, stripping the Controller suffix', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(AuthFixtureController::class, 'index');

    expect((new TagDeriver())->derive($descriptor))->toBe('AuthFixtures');
});

it(
    'falls back to the StudlyCased last route-group prefix segment for a controllerless route',
    function (string $prefix, string $expected): void {
        $route = new Route(['GET'], $prefix . '/x', ['prefix' => $prefix]);
        $descriptor = new ActionDescriptor(
            route: $route,
            controller: null,
            method: null,
            summary: null,
            description: null,
        );

        expect((new TagDeriver())->derive($descriptor))->toBe($expected);
    },
)->with([
    'single-segment prefix' => ['webhooks', 'Webhooks'],
    'multi-segment prefix uses the last segment' => ['api/v1/billing', 'Billing'],
]);

it('falls back to "General" for a controllerless route with no prefix', function (): void {
    $route = new Route(['GET'], '/x', []);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    expect((new TagDeriver())->derive($descriptor))->toBe('General');
});
