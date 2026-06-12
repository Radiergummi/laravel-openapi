<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\InlineValidationFixtureController;

use function array_column;
use function array_filter;
use function array_values;
use function str_replace;

uses()->group('openapi');

// region Helpers

/**
 * The names of an operation's query parameters.
 *
 * @param array<string, mixed> $operation
 *
 * @return list<string>
 */
function queryParameterNames(array $operation): array
{
    return array_values(array_column(
        array_filter(
            $operation['parameters'] ?? [],
            static fn(array $parameter): bool => ($parameter['in'] ?? null) === 'query',
        ),
        'name',
    ));
}

// endregion

// A single `validate(['name' => …])` ruleset gates on the verb being emitted: a query parameter
// on the GET twin, a request body on the POST twin — for both verb orders.
it('gates inline-validate by the emitted verb on a multi-verb route', function (array $verbs): void {
    Route::match($verbs, '/oa-fixture/sync', [InlineValidationFixtureController::class, 'sync']);

    $spec = generateSpec();
    $get = $spec['paths']['/oa-fixture/sync']['get'];
    $post = $spec['paths']['/oa-fixture/sync']['post'];

    // GET twin: `name` is a query parameter, no request body.
    expect(queryParameterNames($get))->toContain('name')
        ->and($get)->not->toHaveKey('requestBody');

    // POST twin: `name` is a request body field, not a query parameter.
    expect($post)->toHaveKey('requestBody')
        ->and(queryParameterNames($post))->not->toContain('name');

    $reference = (string) $post['requestBody']['content']['application/json']['schema']['$ref'];
    $schemaName = str_replace('#/components/schemas/', '', $reference);
    expect($spec['components']['schemas'][$schemaName]['properties'])->toHaveKey('name');
})->with([
    'get-first' => [['get', 'post']],
    'post-first' => [['post', 'get']],
]);
