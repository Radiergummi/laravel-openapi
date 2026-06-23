<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery\SearchController;

uses()->group('openapi');

// region Helpers

/**
 * @param array<string, mixed> $spec
 *
 * @return array<string, array<string, mixed>> query parameters of the operation, keyed by name
 */
function formRequestQueryParameters(array $spec, string $path, string $verb): array
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

it('surfaces a GET FormRequest as query parameters in wire notation with no request body', function (): void {
    Route::get('/oa-fixture/fr-search', [SearchController::class, 'index']);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/fr-search']['get'];
    $parameters = formRequestQueryParameters($spec, '/oa-fixture/fr-search', 'get');

    expect($operation)->not->toHaveKey('requestBody')
        ->and(array_keys($parameters))->toContain('term', 'page', 'filter[name]', 'ids[]')
        ->and($parameters['term']['required'])->toBeTrue()
        ->and($parameters['term']['schema']['type'])->toBe('string')
        ->and($parameters['page']['required'])->toBeFalse()
        ->and($parameters['page']['schema']['type'])->toBe('integer')
        ->and($parameters['filter[name]']['required'])->toBeFalse()
        ->and($parameters['ids[]']['schema']['type'])->toBe('array')
        ->and($parameters['ids[]']['schema']['items']['type'])->toBe('integer')
        ->and($parameters['ids[]']['style'])->toBe('form')
        ->and($parameters['ids[]']['explode'])->toBeTrue()
        // A scalar query parameter carries no serialization keywords.
        ->and($parameters['term'])->not->toHaveKeys(['style', 'explode'])
        ->and($parameters['page'])->not->toHaveKeys(['style', 'explode']);
});

it('does not flag its own inferred array query parameter as missing explode', function (): void {
    Route::get('/oa-fixture/fr-explode', [SearchController::class, 'index']);

    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'parameter.query-array-no-explode',
        '--uri' => 'oa-fixture/fr-explode',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('still emits a request body for the same FormRequest on POST', function (): void {
    Route::post('/oa-fixture/fr-search', [SearchController::class, 'store']);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/fr-search']['post'];
    $parameters = formRequestQueryParameters($spec, '/oa-fixture/fr-search', 'post');

    expect($operation)->toHaveKey('requestBody')
        ->and($parameters)->toBe([]);
});

it('treats HEAD like GET: body suppressed, query parameters emitted', function (): void {
    // Laravel auto-registers HEAD alongside GET; assert on the GET operation it produces.
    Route::get('/oa-fixture/fr-head', [SearchController::class, 'index']);

    $spec = generateSpec();
    $parameters = formRequestQueryParameters($spec, '/oa-fixture/fr-head', 'get');

    expect($spec['paths']['/oa-fixture/fr-head']['get'])->not->toHaveKey('requestBody')
        ->and(array_keys($parameters))->toContain('term');
});

it('leaves a DELETE FormRequest unchanged: no query parameters (out of scope)', function (): void {
    // A DELETE request body is already stripped at serialization for every verb except
    // POST/PUT/PATCH (OperationDescriptor::shouldAttachBody), so a DELETE FormRequest never produced
    // a body. This change does not surface it as query parameters either: DELETE is left untouched,
    // mirroring the inline validate() path's DELETE handling.
    Route::delete('/oa-fixture/fr-delete', [SearchController::class, 'destroy']);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/fr-delete']['delete'];
    $parameters = formRequestQueryParameters($spec, '/oa-fixture/fr-delete', 'delete');

    expect($operation)->not->toHaveKey('requestBody')
        ->and($parameters)->toBe([]);
});

it('degrades to no query parameters and logs a notice when rules() cannot be read', function (): void {
    Route::get('/oa-fixture/fr-throwing', [SearchController::class, 'throwing']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/fr-throwing']['get'];
    $parameters = formRequestQueryParameters($spec, '/oa-fixture/fr-throwing', 'get');

    expect($operation)->not->toHaveKey('requestBody')
        ->and($parameters)->toBe([]);

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'could not be read statically'),
    );

    expect($noted)->toBeTrue();
});

it('drops an array-of-objects rule with a notice', function (): void {
    Route::get('/oa-fixture/fr-aoo', [SearchController::class, 'arrayOfObjects']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $parameters = formRequestQueryParameters($spec, '/oa-fixture/fr-aoo', 'get');

    expect(array_keys($parameters))->toContain('q')
        ->and(array_keys($parameters))->not->toContain('items[]');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'cannot be expressed as query parameters'),
    );

    expect($noted)->toBeTrue();
});

it('lets a #[QueryParam] attribute win over a flattened FormRequest field of the same name', function (): void {
    Route::get('/oa-fixture/fr-attribute', [SearchController::class, 'indexWithAttribute']);

    $spec = generateSpec();
    $parameters = formRequestQueryParameters($spec, '/oa-fixture/fr-attribute', 'get');

    expect($parameters['term']['schema']['description'] ?? null)->toBe('Authored search term.');
});

it('emits no requestBody key at all for a GET route carrying a FormRequest', function (): void {
    Route::get('/oa-fixture/fr-guard', [SearchController::class, 'index']);

    $spec = generateSpec();

    expect($spec['paths']['/oa-fixture/fr-guard']['get'])->not->toHaveKey('requestBody');
});
