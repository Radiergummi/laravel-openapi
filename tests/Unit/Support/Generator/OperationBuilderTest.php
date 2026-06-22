<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\OperationDescriptor;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Tests\Fixtures\AuthoringFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\ParamDocblockQueryController;

use function Radiergummi\OpenApi\is_defined;

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

// region @param query-parameter description fallback

/**
 * Builds the operation for a {@see ParamDocblockQueryController} method with an explicit
 * `paramDescriptions` map injected onto the descriptor, returning its `sort` query parameter.
 *
 * The map is injected rather than authored as a `@param` tag because a query key is not a PHP
 * signature parameter, so the docblock would be stripped by Pint's `no_superfluous_phpdoc_tags`.
 *
 * @param array<string, string> $paramDescriptions
 */
function buildQueryParameter(string $method, array $paramDescriptions): OA\Parameter
{
    Route::get('/op-builder-query/' . $method, [ParamDocblockQueryController::class, $method]);

    $descriptors = array_values(
        array_filter(
            iterator_to_array(app(RouteIntrospector::class)->discover(), false),
            static fn($d): bool
                => $d->method?->getName() === $method
                && $d->controller?->getName() === ParamDocblockQueryController::class,
        ),
    );

    expect($descriptors)->toHaveCount(1);
    $original = $descriptors[0];

    $descriptor = new ActionDescriptor(
        route: $original->route,
        controller: $original->controller,
        method: $original->method,
        summary: $original->summary,
        description: $original->description,
        paramDescriptions: $paramDescriptions,
    );

    $op = app(OperationBuilder::class)->build($descriptor, []);

    $queryParameters = array_values(
        array_filter(
            $op->parameters,
            static fn(OA\Parameter $p): bool => $p->in === 'query' && $p->name === 'sort',
        ),
    );

    expect($queryParameters)->toHaveCount(1);

    return $queryParameters[0];
}

it('fills an undescribed query parameter from the @param description map', function (): void {
    $parameter = buildQueryParameter('accessorRead', ['sort' => 'The sort order.']);

    expect($parameter->schema->description)->toBe('The sort order.');
});

it('keeps a #[QueryParam] description over the @param description', function (): void {
    $parameter = buildQueryParameter('attributeDescribed', ['sort' => 'The docblock sort.']);

    expect($parameter->schema->description)->toBe('The attribute sort.');
});

it('keeps an inline-validate() comment description over the @param description', function (): void {
    $parameter = buildQueryParameter('validateCommented', ['sort' => 'The docblock sort.']);

    expect($parameter->schema->description)->toBe('The inline sort comment.');
});

it('leaves a query parameter undescribed when the @param map has no entry', function (): void {
    $parameter = buildQueryParameter('accessorRead', []);

    expect(is_defined($parameter->schema->description))->toBeFalse();
});

// endregion
