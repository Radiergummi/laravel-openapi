<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\Hide;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Attributes\Response;

uses()->group('openapi');

beforeEach(function (): void {
    /*
     * Plain visible closure route — used to verify the spec includes it
     * and picks up docblock summary and Operation attribute.
     *
     * Returns the SPA shell.
     */
    Route::get('/closure/visible', #[Operation(summary: 'Closure summary override')] static fn(): array => []);

    $this->spec = generateSpec();
});

// region #[Hide] on a closure

it('excludes a closure route carrying bare #[Hide] from the spec', function (): void {
    Route::get('/closure/hidden', #[Hide] static fn(): array => []);

    $spec = generateSpec();

    expect($spec['paths'])->not->toHaveKey('/closure/hidden');
});

it('keeps an env-scoped #[Hide] closure visible in a non-matching environment', function (): void {
    // The test environment is "testing"; hiding only in production must leave the route visible.
    Route::get('/closure/env-hidden', #[Hide(only: ['production'])] static fn(): array => []);

    $spec = generateSpec();

    expect($spec['paths'])->toHaveKey('/closure/env-hidden');
});

it('excludes an env-scoped #[Hide] closure when the current environment matches', function (): void {
    Route::get('/closure/env-hidden', #[Hide(only: ['production'])] static fn(): array => []);

    app()['env'] = 'production';

    $spec = generateSpec();

    expect($spec['paths'])->not->toHaveKey('/closure/env-hidden');
});

// endregion

// region #[Operation] on a closure

it('picks up the summary from a #[Operation] attribute on a closure', function (): void {
    $operation = $this->spec['paths']['/closure/visible']['get'];

    expect($operation['summary'])->toBe('Closure summary override');
});

// endregion

// region #[Response] on a closure

it('picks up a #[Response] attribute declared on a closure', function (): void {
    Route::post('/closure/created', #[Response(status: 201, description: 'Created')] static fn(): array => []);

    $spec = generateSpec();

    $responses = $spec['paths']['/closure/created']['post']['responses'];

    expect($responses)->toHaveKey('201')
        ->and($responses['201']['description'])->toBe('Created')
        ->and($responses)->not->toHaveKey('200');
});

// endregion

// region Docblock summary on a closure

it('picks up the docblock summary from a closure route', function (): void {
    /**
     * Returns the SPA shell.
     */
    $closure = static fn(): array => [];

    Route::get('/closure/docblock', $closure);

    $spec = generateSpec();

    $operation = $spec['paths']['/closure/docblock']['get'];

    expect($operation['summary'])->toBe('Returns the SPA shell.');
});

// endregion
