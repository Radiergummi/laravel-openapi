<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\ResponseHeaderClassFixtureController;

uses()->group('openapi');

beforeEach(function (): void {
    Route::get('/oa-fixture/response-header/inherited', [ResponseHeaderClassFixtureController::class, 'inheritedHeaderAction']);
    Route::get('/oa-fixture/response-header/override', [ResponseHeaderClassFixtureController::class, 'overrideAction']);

    $this->spec = generateSpec();
});

it('applies class-level #[ResponseHeader] to every action on the controller', function (): void {
    $headers = $this->spec['paths']['/oa-fixture/response-header/inherited']['get']['responses']['200']['headers'];

    expect($headers)->toHaveKey('X-Request-Id')
        ->and($headers['X-Request-Id']['description'])->toBe('Per-request correlation id');
});

it('lets method-level #[ResponseHeader] win on (status, name) collision', function (): void {
    $headers = $this->spec['paths']['/oa-fixture/response-header/override']['get']['responses']['200']['headers'];

    expect($headers)->toHaveKey('X-Request-Id')
        ->and($headers['X-Request-Id']['description'])->toBe('Overridden by the method')
        ->and($headers)->toHaveKey('X-RateLimit-Remaining')
        ->and($headers['X-RateLimit-Remaining']['schema']['type'])->toBe('integer')
        ->and($headers['X-RateLimit-Remaining']['schema']['format'])->toBe('int32');
});
