<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\FindOrFailFixtureController;

uses()->group('openapi');

// region Inferred 404 responses

it('emits a 404 for a static Model::findOrFail() in the body', function (): void {
    Route::get('/oa-fixture/articles/{id}', [FindOrFailFixtureController::class, 'staticFindOrFail']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/articles/{id}']['get']['responses'];

    expect($responses)->toHaveKey('404');
});

it('emits a 404 for a ->firstOrFail() in the body', function (): void {
    Route::get('/oa-fixture/articles', [FindOrFailFixtureController::class, 'firstOrFail']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/articles']['get']['responses'];

    expect($responses)->toHaveKey('404');
});

it('emits no 404 for a non-throwing find() / firstOr()', function (): void {
    Route::get('/oa-fixture/articles/maybe/{id}', [FindOrFailFixtureController::class, 'nonThrowingFind']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/articles/maybe/{id}']['get']['responses'];

    expect($responses)->not->toHaveKey('404');
});

// endregion

// region Dedup against the route-model binding 404

it('emits exactly one 404 when binding and findOrFail both fire', function (): void {
    // {article} binds the Article model (→ binding 404); the body also calls Article::findOrFail().
    Route::get('/oa-fixture/articles/{article}/related/{id}', [
        FindOrFailFixtureController::class,
        'boundAndFindOrFail',
    ]);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/articles/{article}/related/{id}']['get']['responses'];

    expect($responses)->toHaveKey('404')
        // The binding contributor and the body scan source the same config entry, so the single
        // emitted 404 reuses the shared component rather than duplicating the status.
        ->and($responses['404']['$ref'])->toBe('#/components/responses/NotFound');
});

// endregion
