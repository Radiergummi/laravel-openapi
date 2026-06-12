<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\OperationDescriptor;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceConventionDefaultController;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceConventionLiteralController;

uses()->group('openapi');

// region Helpers

/**
 * Builds the operation for a resourceful action via the live pipeline, registering the route under
 * the given verb so the resource convention's verb gate fires (a `store` must be reached by POST).
 *
 * @param class-string $controller
 */
function buildResourceOperation(string $verb, string $uri, string $controller, string $method): OperationDescriptor
{
    Route::{$verb}($uri, [$controller, $method]);

    $descriptors = array_values(array_filter(
        iterator_to_array(app(RouteIntrospector::class)->discover(), false),
        static fn($d): bool => $d->method?->getName() === $method
            && $d->controller?->getName() === $controller,
    ));

    expect($descriptors)->toHaveCount(1);

    return app(OperationBuilder::class)->build($descriptors[0], []);
}

function resourcePrimaryStatus(OperationDescriptor $op): string
{
    return (string) $op->responses[0]->response;
}

// endregion

// region Body-scan literal status vs. resource convention (#240)

it('keeps a body-scan literal 200 over the store convention (201)', function (): void {
    $op = buildResourceOperation('post', '/widgets', ResourceConventionLiteralController::class, 'store');

    expect(resourcePrimaryStatus($op))->toBe('200');
});

it('keeps a body-scan literal 201 over the index convention (200)', function (): void {
    $op = buildResourceOperation('get', '/widgets', ResourceConventionLiteralController::class, 'index');

    expect(resourcePrimaryStatus($op))->toBe('201');
});

it('still applies the resource convention when the json() call has no explicit status', function (): void {
    $op = buildResourceOperation('post', '/things', ResourceConventionDefaultController::class, 'store');

    expect(resourcePrimaryStatus($op))->toBe('201');
});

it('does not leak the transient explicit-status marker into the serialized response', function (): void {
    $op = buildResourceOperation('post', '/widgets', ResourceConventionLiteralController::class, 'store');

    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($op->responses[0], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toHaveKey('x-laravel-openapi-explicit-status');
});

// endregion
