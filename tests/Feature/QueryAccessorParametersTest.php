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
