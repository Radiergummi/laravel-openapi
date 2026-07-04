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

/**
 * Like {@see buildOperation()} but registers the route behind the given middleware, so the
 * throttle-derived rate-limit headers can be exercised.
 *
 * @param list<string> $middleware
 */
function buildOperationBehind(string $method, array $middleware): OperationDescriptor
{
    Route::get('/op-builder-mw/' . $method, [AuthoringFixtureController::class, $method])
        ->middleware($middleware);

    $descriptors = array_values(
        array_filter(
            iterator_to_array(app(RouteIntrospector::class)->discover(), false),
            static fn($d): bool
                => $d->method?->getName() === $method
                && $d->controller?->getName() === AuthoringFixtureController::class
                && str_starts_with((string) $d->route->uri(), 'op-builder-mw/'),
        ),
    );

    expect($descriptors)->toHaveCount(1);

    return app(OperationBuilder::class)->build($descriptors[0], []);
}

/**
 * Returns the `OA\Header` named `$name` on the response with status `$status`, or null.
 */
function responseHeader(OperationDescriptor $op, string $status, string $name): ?OA\Header
{
    foreach ($op->responses as $response) {
        if ((string) $response->response !== $status) {
            continue;
        }

        foreach (is_array($response->headers) ? $response->headers : [] as $header) {
            if ($header instanceof OA\Header && $header->header === $name) {
                return $header;
            }
        }
    }

    return null;
}

/**
 * @return list<string> Every header name across every response of the operation.
 */
function responseHeaderNames(OperationDescriptor $op): array
{
    $names = [];

    foreach ($op->responses as $response) {
        foreach (is_array($response->headers) ? $response->headers : [] as $header) {
            if ($header instanceof OA\Header && is_string($header->header)) {
                $names[] = $header->header;
            }
        }
    }

    return $names;
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

it('lets a #[Header] attribute win over an inferred header read of the same name', function (): void {
    $op = buildOperation('inferredHeaderOverriddenByAttributeAction');

    $headers = array_values(
        array_filter($op->parameters, static fn(OA\Parameter $p): bool => $p->in === 'header'),
    );

    expect($headers)->toHaveCount(1)
        ->and($headers[0]->name)->toBe('X-Api-Key')
        ->and($headers[0]->required)->toBeTrue()
        ->and($headers[0]->description)->toBe('Authored API key.');
});

it('collapses a case-differing inferred header read and #[Header] into one, attribute casing wins', function (): void {
    $op = buildOperation('caseVariantHeaderInferredAndAttributeAction');

    $headers = array_values(
        array_filter($op->parameters, static fn(OA\Parameter $p): bool => $p->in === 'header'),
    );

    expect($headers)->toHaveCount(1)
        ->and($headers[0]->name)->toBe('x-api-key')
        ->and($headers[0]->required)->toBeTrue()
        ->and($headers[0]->description)->toBe('Authored API key.');
});

it('collapses two case-differing #[Header] attributes into one, last writer wins', function (): void {
    $op = buildOperation('caseVariantHeaderAttributesAction');

    $headers = array_values(
        array_filter($op->parameters, static fn(OA\Parameter $p): bool => $p->in === 'header'),
    );

    expect($headers)->toHaveCount(1)
        ->and($headers[0]->name)->toBe('x-request-id')
        ->and($headers[0]->required)->toBeTrue();
});

it('collapses two case-differing inferred header reads into one', function (): void {
    $op = buildOperation('caseVariantInferredHeadersAction');

    $headers = array_values(
        array_filter($op->parameters, static fn(OA\Parameter $p): bool => $p->in === 'header'),
    );

    expect($headers)->toHaveCount(1);
});

it('lets a #[CookieParam] attribute win over an inferred cookie read of the same name', function (): void {
    $op = buildOperation('inferredCookieOverriddenByAttributeAction');

    $cookies = array_values(
        array_filter($op->parameters, static fn(OA\Parameter $p): bool => $p->in === 'cookie'),
    );

    expect($cookies)->toHaveCount(1)
        ->and($cookies[0]->name)->toBe('session')
        ->and($cookies[0]->required)->toBeTrue();
});

it('keeps inferred query, cookie, and header reads of the same name as three parameters', function (): void {
    $op = buildOperation('inferredRequestLocationsAction');

    $locations = [];

    foreach ($op->parameters as $parameter) {
        if ($parameter->name === 'token') {
            $locations[] = $parameter->in;
        }
    }

    expect($locations)->toContain('query')
        ->and($locations)->toContain('cookie')
        ->and($locations)->toContain('header')
        ->and($locations)->toHaveCount(3);
});

it('populates externalDocs from #[ExternalDocs]', function (): void {
    $op = buildOperation('withExternalDocsAction');

    expect($op->externalDocs)->not
        ->toBeNull()
        ->and($op->externalDocs?->url)->toBe('https://notion.so/runbook');
});

// endregion

// region conventional response headers

it('emits a Location header on a 201 response', function (): void {
    $op = buildOperation('createdResponseAction');

    $location = responseHeader($op, '201', 'Location');

    expect($location)->not
        ->toBeNull()
        ->and($location?->schema->type)->toBe('string')
        ->and($location?->schema->format)->toBe('uri-reference');
});

it('emits no Location header when no response is a 201', function (): void {
    $op = buildOperation('publicAction');

    expect(responseHeaderNames($op))->not->toContain('Location');
});

it('emits the rate-limit headers on a throttle:args route', function (): void {
    $op = buildOperationBehind('publicAction', ['throttle:60,1']);

    $primaryStatus = (string) $op->responses[0]->response;

    expect(responseHeader($op, $primaryStatus, 'X-RateLimit-Limit')?->schema->type)->toBe('integer')
        ->and(responseHeader($op, $primaryStatus, 'X-RateLimit-Remaining')?->schema->type)->toBe('integer');
});

it('emits the rate-limit headers on a bare throttle route', function (): void {
    $op = buildOperationBehind('publicAction', ['throttle']);

    $primaryStatus = (string) $op->responses[0]->response;

    expect(responseHeader($op, $primaryStatus, 'X-RateLimit-Limit'))->not
        ->toBeNull()
        ->and(responseHeader($op, $primaryStatus, 'X-RateLimit-Remaining'))->not->toBeNull();
});

it('emits no rate-limit headers when the route is not throttled', function (): void {
    $op = buildOperation('publicAction');

    expect(responseHeaderNames($op))
        ->not->toContain('X-RateLimit-Limit')
        ->not->toContain('X-RateLimit-Remaining');
});

it('emits only Location on an un-throttled 201 route', function (): void {
    $op = buildOperation('createdResponseAction');

    expect(responseHeader($op, '201', 'Location'))->not
        ->toBeNull()
        ->and(responseHeaderNames($op))
        ->not->toContain('X-RateLimit-Limit')
        ->not->toContain('X-RateLimit-Remaining');
});

it('emits only the rate-limit headers on a throttled non-201 route', function (): void {
    $op = buildOperationBehind('publicAction', ['throttle:60,1']);

    $primaryStatus = (string) $op->responses[0]->response;

    expect(responseHeader($op, $primaryStatus, 'X-RateLimit-Limit'))->not
        ->toBeNull()
        ->and(responseHeaderNames($op))->not->toContain('Location');
});

it('keeps an authored Location header over the convention on a 201 response', function (): void {
    $op = buildOperation('authoredLocationAction');

    $locations = array_values(
        array_filter(
            responseHeaderNames($op),
            static fn(string $name): bool => $name === 'Location',
        ),
    );

    expect($locations)->toHaveCount(1)
        ->and(responseHeader($op, '201', 'Location')?->description)->toBe('Authored location');
});

it('keeps an authored rate-limit header while the sibling is still derived', function (): void {
    $op = buildOperationBehind('authoredRateLimitAction', ['throttle:60,1']);

    $primaryStatus = (string) $op->responses[0]->response;

    $limits = array_values(
        array_filter(
            responseHeaderNames($op),
            static fn(string $name): bool => $name === 'X-RateLimit-Limit',
        ),
    );

    expect($limits)->toHaveCount(1)
        ->and(responseHeader($op, $primaryStatus, 'X-RateLimit-Limit')?->description)->toBe('Authored limit')
        ->and(responseHeader($op, $primaryStatus, 'X-RateLimit-Remaining'))->not->toBeNull();
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
