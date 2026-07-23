<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\OperationDescriptor;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceConventionDefaultController;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceConventionLiteralController;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceDestroyEmptyJsonController;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceDestroyExplicitStatusController;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceDestroyNoContentController;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceStoreEmptyJsonController;

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

it('keeps a chained ->setStatusCode(200) over the store convention (201)', function (): void {
    $op = buildResourceOperation('post', '/widgets', ResourceConventionLiteralController::class, 'storeSetStatusCode');

    expect(resourcePrimaryStatus($op))->toBe('200');
});

it('keeps a ->noContent() 204 over the store convention (201)', function (): void {
    $op = buildResourceOperation('post', '/widgets', ResourceConventionLiteralController::class, 'storeNoContent');

    expect(resourcePrimaryStatus($op))->toBe('204');
});

it('still applies the resource convention when the json() call has no explicit status', function (): void {
    $op = buildResourceOperation('post', '/things', ResourceConventionDefaultController::class, 'store');

    expect(resourcePrimaryStatus($op))->toBe('201');
});

it('keeps a content-bearing body-scan 200 over the destroy convention (204)', function (): void {
    $op = buildResourceOperation('delete', '/widgets/{widget}', ResourceConventionLiteralController::class, 'destroy');

    expect(resourcePrimaryStatus($op))->toBe('200');

    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($op->responses[0], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized['content']['application/json']['schema']['properties'])->toHaveKey('message')
        ->and($serialized['content']['application/json']['schema']['properties']['message']['type'])->toBe('string');
});

it('keeps a body-less ->noContent() 204 for the destroy convention', function (): void {
    $op = buildResourceOperation('delete', '/widgets/{widget}', ResourceDestroyNoContentController::class, 'destroy');

    expect(resourcePrimaryStatus($op))->toBe('204');

    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($op->responses[0], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toHaveKey('content');
});

it('keeps an explicit json([...], 202) status over the destroy convention (204)', function (): void {
    $op = buildResourceOperation('delete', '/widgets/{widget}', ResourceDestroyExplicitStatusController::class, 'destroy');

    expect(resourcePrimaryStatus($op))->toBe('202');
});

it('keeps the destroy convention 204 for an empty json([]) body', function (): void {
    $op = buildResourceOperation('delete', '/widgets/{widget}', ResourceDestroyEmptyJsonController::class, 'destroy');

    expect(resourcePrimaryStatus($op))->toBe('204');

    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($op->responses[0], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toHaveKey('content');
});

it('still relabels an empty json([]) store body to the store convention (201)', function (): void {
    $op = buildResourceOperation('post', '/widgets', ResourceStoreEmptyJsonController::class, 'store');

    expect(resourcePrimaryStatus($op))->toBe('201');
});

it('does not leak an explicit-status marker into the serialized response', function (): void {
    $op = buildResourceOperation('post', '/widgets', ResourceConventionLiteralController::class, 'store');

    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($op->responses[0], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toHaveKey('x-laravel-openapi-explicit-status');
});

// endregion
