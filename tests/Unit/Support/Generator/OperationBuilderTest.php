<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\OperationDescriptor;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Tests\Fixtures\AuthoringFixtureController;

uses()->group('openapi');

// region Helpers

/**
 * Builds the operation for `AuthoringFixtureController::$method` via the live pipeline.
 *
 * `OperationBuilder` calls `SecurityExtractor`, which calls `Route::controllerDispatcher()`,
 * which needs the route to be bound into the application — so a hand-constructed `ActionDescriptor`
 * (via `ActionDescriptorFactory`) is not sufficient here. The test registers a real route, then
 * walks `RouteIntrospector::discover()` to retrieve the matching descriptor.
 */
function buildOperation(string $method, array $tags = []): OperationDescriptor
{
    Route::get('/op-builder/' . $method, [AuthoringFixtureController::class, $method]);

    $descriptors = array_values(
        array_filter(
            iterator_to_array(app(RouteIntrospector::class)->discover(), false),
            static fn($d): bool
                => $d->method?->getName() === $method
                && $d->controller?->getName() === AuthoringFixtureController::class,
        ),
    );

    expect($descriptors)->toHaveCount(1);

    return app(OperationBuilder::class)->build($descriptors[0], $tags);
}

// endregion

// region build()

it('builds a baseline OperationDescriptor with a default 200 response', function (): void {
    $op = buildOperation('publicAction', ['Widgets']);

    expect($op)
        ->toBeInstanceOf(OperationDescriptor::class)
        ->and($op->tags)->toBe(['Widgets'])
        ->and($op->responses)->not
        ->toBeEmpty()
        ->and($op->deprecated)->toBeFalse()
        ->and($op->responses[0]->response)->toBe('200');
});

it('uses an explicit #[Response(status: 201)] as the primary response', function (): void {
    $op = buildOperation('createdResponseAction');

    $statuses = array_map(static fn(OA\Response $r): string => (string) $r->response, $op->responses);

    expect($statuses)
        ->toContain('201')
        ->and($statuses)->not->toContain('200');
});

it('marks an operation deprecated from a @deprecated PHPDoc tag', function (): void {
    $op = buildOperation('deprecatedViaDocBlockAction');

    expect($op->deprecated)
        ->toBeTrue()
        ->and($op->description)->toContain('**Deprecated:** Use createdResponseAction() instead.');
});

it('lets a #[Deprecated] attribute win over the @deprecated PHPDoc tag', function (): void {
    $op = buildOperation('deprecatedViaAttributeAndDocBlockAction');

    expect($op->deprecated)
        ->toBeTrue()
        ->and($op->description)->toContain('**Deprecated:** Attribute reason.')
        ->and($op->description)->not->toContain('Doc reason.');
});

it('merges multiple 2xx #[Response] attributes: first primary, rest additional', function (): void {
    $op = buildOperation('multiTwoxxResponseAction');

    $statuses = array_map(static fn(OA\Response $r): string => (string) $r->response, $op->responses);

    expect($statuses)
        ->toContain('201')
        ->and($statuses)->toContain('202')
        ->and($statuses)->not->toContain('200');
});

it('emits header parameters from #[Header] attributes', function (): void {
    $op = buildOperation('headeredAction');

    $headerNames = array_values(
        array_map(
            static fn(OA\Parameter $p): string => (string) $p->name,
            array_filter(
                $op->parameters,
                static fn(OA\Parameter $p): bool => $p->in === 'header',
            ),
        ),
    );

    expect($headerNames)
        ->toContain('X-Tenant-Id')
        ->and($headerNames)->toContain('Idempotency-Key');
});

it('populates externalDocs from #[ExternalDocs]', function (): void {
    $op = buildOperation('withExternalDocsAction');

    expect($op->externalDocs)->not
        ->toBeNull()
        ->and($op->externalDocs?->url)->toBe('https://notion.so/runbook');
});

// endregion
