<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\QueryAccessorFixtureController;

uses()->group('openapi');

// region Helpers

/**
 * @param array<string, mixed> $spec
 *
 * @return array<string, array<string, mixed>> query parameters of the operation, keyed by name
 */
function accessorQueryParameters(array $spec, string $path, string $verb): array
{
    $parameters = [];

    foreach ($spec['paths'][$path][$verb]['parameters'] ?? [] as $parameter) {
        if ($parameter['in'] === 'query') {
            $parameters[$parameter['name']] = $parameter;
        }
    }

    return $parameters;
}

/**
 * @param array<string, mixed> $spec
 *
 * @return array<string, array<string, mixed>> parameters of the operation, keyed by "in:name"
 */
function accessorParametersByLocation(array $spec, string $path, string $verb): array
{
    $parameters = [];

    foreach ($spec['paths'][$path][$verb]['parameters'] ?? [] as $parameter) {
        $parameters[$parameter['in'] . ':' . $parameter['name']] = $parameter;
    }

    return $parameters;
}

// endregion

// region Accessor reads

it('documents request-accessor reads as query parameters end to end', function (): void {
    Route::get('/oa-fixture/accessors', [QueryAccessorFixtureController::class, 'index']);

    $spec = generateSpec();
    $parameters = accessorQueryParameters($spec, '/oa-fixture/accessors', 'get');

    expect(array_keys($parameters))
        ->toBe(['sort', 'q', 'name', 'page', 'active'])
        ->and($parameters['page']['schema']['type'])->toBe('integer')
        ->and($parameters['active']['schema']['type'])->toBe('boolean')
        ->and($parameters['sort']['required'])->toBeFalse();
});

it('documents accessor defaults on the parameter schema', function (): void {
    Route::get('/oa-fixture/defaults', [QueryAccessorFixtureController::class, 'withDefaults']);

    $spec = generateSpec();
    $parameters = accessorQueryParameters($spec, '/oa-fixture/defaults', 'get');

    expect($parameters['per_page']['schema']['default'])
        ->toBe(25)
        ->and($parameters['sort']['schema']['default'])->toBe('asc')
        ->and($parameters['page']['schema'])->not->toHaveKey('default');
});

it('keeps only the query() read on a body-carrying verb', function (): void {
    Route::post('/oa-fixture/accessors', [QueryAccessorFixtureController::class, 'index']);

    $spec = generateSpec();
    $parameters = accessorQueryParameters($spec, '/oa-fixture/accessors', 'post');

    expect(array_keys($parameters))->toBe(['sort']);
});

it('lets an explicit #[QueryParam] win over an accessor read of the same name', function (): void {
    Route::get('/oa-fixture/override', [QueryAccessorFixtureController::class, 'attributeOverride']);

    $spec = generateSpec();
    $parameters = accessorQueryParameters($spec, '/oa-fixture/override', 'get');

    expect(array_keys($parameters))
        ->toBe(['sort', 'q'])
        ->and($parameters['sort']['schema']['description'])->toBe('Sort order.')
        ->and($parameters['sort']['schema']['enum'])->toBe(['asc', 'desc']);
});

it('documents cookie() and header() reads as cookie/header parameters end to end', function (): void {
    Route::post('/oa-fixture/request-locations', [QueryAccessorFixtureController::class, 'cookieAndHeaderReads']);

    $spec = generateSpec();

    $parameters = [];

    foreach ($spec['paths']['/oa-fixture/request-locations']['post']['parameters'] ?? [] as $parameter) {
        $parameters[$parameter['in'] . ':' . $parameter['name']] = $parameter;
    }

    // Cookies/headers are verb-independent, so both appear even on a POST route.
    expect($parameters)->toHaveKey('cookie:session')
        ->and($parameters['cookie:session']['schema']['type'])->toBe('string')
        ->and($parameters)->toHaveKey('header:X-Api-Key')
        ->and($parameters['header:X-Api-Key']['schema']['type'])->toBe('string');
});

// endregion

// region Reserved-header denylist

it('filters reserved / protocol headers out of inferred header parameters', function (): void {
    Route::post('/oa-fixture/reserved-headers', [QueryAccessorFixtureController::class, 'reservedHeaderReads']);

    $spec = generateSpec();
    $parameters = accessorParametersByLocation($spec, '/oa-fixture/reserved-headers', 'post');
    $headers = array_filter($parameters, static fn(array $parameter): bool => $parameter['in'] === 'header');

    expect($headers)->toBe([]);
});

it('folds case when matching reserved headers', function (): void {
    Route::post('/oa-fixture/reserved-headers-case', [QueryAccessorFixtureController::class, 'caseInsensitiveReservedHeaders']);

    $spec = generateSpec();
    $parameters = accessorParametersByLocation($spec, '/oa-fixture/reserved-headers-case', 'post');
    $headers = array_filter($parameters, static fn(array $parameter): bool => $parameter['in'] === 'header');

    expect($headers)->toBe([]);
});

it('still surfaces custom (non-reserved) headers, including X-Forwarded-For', function (): void {
    Route::post('/oa-fixture/custom-headers', [QueryAccessorFixtureController::class, 'customHeaderReads']);

    $spec = generateSpec();
    $parameters = accessorParametersByLocation($spec, '/oa-fixture/custom-headers', 'post');

    expect($parameters)->toHaveKey('header:X-Api-Key')
        ->and($parameters)->toHaveKey('header:Stripe-Signature')
        ->and($parameters)->toHaveKey('header:X-Forwarded-For');
});

it('keeps an explicit #[Header] of a reserved name (the attribute is never filtered)', function (): void {
    Route::post('/oa-fixture/authorization-header', [QueryAccessorFixtureController::class, 'authorizationHeaderAttribute']);

    $spec = generateSpec();
    $parameters = accessorParametersByLocation($spec, '/oa-fixture/authorization-header', 'post');
    $authHeaders = array_filter(
        $parameters,
        static fn(array $parameter): bool => $parameter['in'] === 'header' && $parameter['name'] === 'Authorization',
    );

    // The attribute wins and the inferred read of the same name is filtered: exactly one param.
    expect($authHeaders)->toHaveCount(1)
        ->and($parameters['header:Authorization']['description'])->toBe('Bearer token.');
});

it('does not filter a reserved name read from the cookie location', function (): void {
    Route::post('/oa-fixture/reserved-cookie', [QueryAccessorFixtureController::class, 'reservedNameOnCookie']);

    $spec = generateSpec();
    $parameters = accessorParametersByLocation($spec, '/oa-fixture/reserved-cookie', 'post');

    expect($parameters)->toHaveKey('cookie:Content-Type');
});

// endregion

// region GET inline-validate hand-off

it('routes inline validate() keys on a GET route into query parameters, not a request body', function (): void {
    Route::get('/oa-fixture/search', [QueryAccessorFixtureController::class, 'search']);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/search']['get'];
    $parameters = accessorQueryParameters($spec, '/oa-fixture/search', 'get');

    expect($operation)->not
        ->toHaveKey('requestBody')
        ->and(array_keys($parameters))->toBe(['q', 'page'])
        ->and($parameters['q']['required'])->toBeTrue()
        ->and($parameters['q']['schema']['maxLength'])->toBe(100)
        ->and($parameters['q']['schema']['description'])->toBe('Free-text search query.')
        ->and($parameters['page']['required'])->toBeFalse();
});

it('maps nested validate() keys to wire notation and notes dropped object arrays', function (): void {
    Route::get('/oa-fixture/nested-search', [QueryAccessorFixtureController::class, 'nestedSearch']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $parameters = accessorQueryParameters($spec, '/oa-fixture/nested-search', 'get');

    expect(array_keys($parameters))
        ->toBe(['filter[name]', 'ids[]'])
        ->and($parameters['filter[name]']['required'])->toBeTrue()
        ->and($parameters['ids[]']['schema']['type'])->toBe('array')
        ->and($parameters['ids[]']['schema']['items']['type'])->toBe('integer')
        ->and(
            array_any(
                $logger->records,
                static fn(array $record): bool => str_contains(
                    $record['message'],
                    'cannot be expressed as query parameters',
                ),
            ),
        )->toBeTrue();
});

it('keeps the validate() request body on a POST route instead of emitting query parameters', function (): void {
    Route::post('/oa-fixture/search', [QueryAccessorFixtureController::class, 'search']);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/search']['post'];

    expect($operation)
        ->toHaveKey('requestBody')
        ->and(accessorQueryParameters($spec, '/oa-fixture/search', 'post'))->toBe([]);
});

// endregion
