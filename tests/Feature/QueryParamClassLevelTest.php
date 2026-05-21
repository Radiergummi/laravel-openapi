<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\QueryParamClassFixtureController;

uses()->group('openapi');

beforeEach(function (): void {
    Route::get('/oa-fixture/query-param/inherited', [QueryParamClassFixtureController::class, 'inheritedAction']);
    Route::get('/oa-fixture/query-param/override', [QueryParamClassFixtureController::class, 'overrideAction']);

    $this->spec = generateSpec();
});

/**
 * @param array<int, array<string, mixed>> $parameters
 *
 * @return array<string, array<string, mixed>>
 */
function queryParameters(array $parameters): array
{
    return array_column(
        array_filter(
            $parameters,
            static fn(array $p): bool => ($p['in'] ?? null) === 'query',
        ),
        null,
        'name',
    );
}

it('applies class-level #[QueryParam] to every action on the controller', function (): void {
    $params = queryParameters($this->spec['paths']['/oa-fixture/query-param/inherited']['get']['parameters'] ?? []);

    expect($params)->toHaveKeys(['tenant', 'locale'])
        ->and($params['tenant']['schema']['type'])->toBe('string')
        ->and($params['tenant']['schema']['description'])->toBe('Active tenant slug')
        ->and($params['locale']['schema']['default'])->toBe('en');
});

it('lets method-level #[QueryParam] override the class-level entry with the same name and append new ones', function (): void {
    $params = queryParameters($this->spec['paths']['/oa-fixture/query-param/override']['get']['parameters'] ?? []);

    expect($params)->toHaveKeys(['tenant', 'locale', 'page'])
        ->and($params['locale']['schema']['default'])->toBe('de')
        ->and($params['page']['schema']['type'])->toBe('integer')
        ->and($params['page']['schema']['default'])->toBe(1)
        ->and($params['page']['schema']['minimum'])->toBe(1);
});
