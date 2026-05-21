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

uses()->group('openapi');

beforeEach(function (): void {
    Route::get('/oa-fixture/query-param/inherited', [QueryParamClassFixtureController::class, 'inheritedAction']);
    Route::get('/oa-fixture/query-param/override', [QueryParamClassFixtureController::class, 'overrideAction']);

    $this->spec = generateSpec();
});

/**
 * @param array<int, array<string, mixed>> $parameters
 *
 * @return array<int, array<string, mixed>>
 */
function queryParameters(array $parameters): array
{
    return array_values(array_filter(
        $parameters,
        static fn(array $p): bool => ($p['in'] ?? null) === 'query',
    ));
}

it('applies class-level #[QueryParam] to every action on the controller', function (): void {
    $params = queryParameters($this->spec['paths']['/oa-fixture/query-param/inherited']['get']['parameters'] ?? []);

    expect(array_column($params, 'name'))->toBe(['tenant', 'locale']);

    $tenant = $params[array_search('tenant', array_column($params, 'name'), true)];
    $locale = $params[array_search('locale', array_column($params, 'name'), true)];

    expect($tenant['schema']['type'])->toBe('string')
        ->and($tenant['schema']['description'])->toBe('Active tenant slug')
        ->and($locale['schema']['default'])->toBe('en');
});

it('lets method-level #[QueryParam] override the class-level entry with the same name and append new ones', function (): void {
    $params = queryParameters($this->spec['paths']['/oa-fixture/query-param/override']['get']['parameters'] ?? []);

    expect(array_column($params, 'name'))->toBe(['tenant', 'locale', 'page']);

    $locale = $params[array_search('locale', array_column($params, 'name'), true)];
    $page = $params[array_search('page', array_column($params, 'name'), true)];

    expect($locale['schema']['default'])->toBe('de')
        ->and($page['schema']['type'])->toBe('integer')
        ->and($page['schema']['default'])->toBe(1)
        ->and($page['schema']['minimum'])->toBe(1);
});
