<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\SameClassHelperController;

uses()->group('openapi');

it('documents a contentless 204 for an action returning a same-class $this->empty() helper', function (): void {
    Route::delete('/oa-fixture/same-class/destroy', [SameClassHelperController::class, 'destroy']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/same-class/destroy']['delete']['responses'];

    expect($responses)
        ->toHaveKey('204')
        ->and($responses['204']['description'])->toBe('No Content')
        ->and($responses['204'])->not->toHaveKey('content');
});

it('honours the derived 204 over the resource-action status convention', function (): void {
    // A `store`-named action conventionally documents 201; the body-less helper's explicit 204 must
    // win, proving the explicit-status marker survives end to end.
    Route::post('/oa-fixture/same-class/store', [SameClassHelperController::class, 'destroy']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/same-class/store']['post']['responses'];

    expect($responses)
        ->toHaveKey('204')
        ->and($responses)->not->toHaveKey('201');
});

it('documents a contentless 204 for a factory-accessor helper (the motivating shape)', function (): void {
    Route::delete('/oa-fixture/same-class/via-factory', [SameClassHelperController::class, 'viaFactory']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/same-class/via-factory']['delete']['responses'];

    expect($responses)
        ->toHaveKey('204')
        ->and($responses['204']['description'])->toBe('No Content')
        ->and($responses['204'])->not->toHaveKey('content');
});

it('reads an explicit status argument on the helper end to end', function (): void {
    Route::put('/oa-fixture/same-class/reset', [SameClassHelperController::class, 'reset']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/same-class/reset']['put']['responses'];

    expect($responses)
        ->toHaveKey('205')
        ->and($responses['205'])->not->toHaveKey('content');
});
