<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fortify;

use Closure;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\PasswordConfirmedResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Contracts\PasswordUpdateResponse;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses as FortifyResponses;
use Radiergummi\OpenApi\Plugins\Fortify\FortifyPlugin;

use function array_keys;

uses()->group('openapi', 'plugin:fortify');

/**
 * Binds the stock Fortify response contracts exactly as FortifyServiceProvider does (string-class
 * singletons) so the customization gate sees real bindings, then runs generation with the plugin
 * enabled and the fixture routes loaded.
 *
 * @param ?Closure(\Illuminate\Contracts\Foundation\Application): void $rebind Optional contract rebinding.
 *
 * @return array<string, mixed>
 */
function generateWithFortifyPlugin(?Closure $rebind = null): array
{
    config(['openapi.plugins' => [FortifyPlugin::class]]);

    $app = app();
    $app->singleton(LoginResponse::class, FortifyResponses\LoginResponse::class);
    $app->singleton(LogoutResponse::class, FortifyResponses\LogoutResponse::class);
    $app->singleton(RegisterResponse::class, FortifyResponses\RegisterResponse::class);
    $app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, FortifyResponses\SuccessfulPasswordResetLinkRequestResponse::class);
    $app->singleton(PasswordResetResponse::class, FortifyResponses\PasswordResetResponse::class);
    $app->singleton(PasswordConfirmedResponse::class, FortifyResponses\PasswordConfirmedResponse::class);
    $app->singleton(PasswordUpdateResponse::class, FortifyResponses\PasswordUpdateResponse::class);
    $app->singleton(ProfileInformationUpdatedResponse::class, FortifyResponses\ProfileInformationUpdatedResponse::class);

    if ($rebind !== null) {
        $rebind($app);
    }

    require __DIR__ . '/../../../Fixtures/Fortify/routes.php';

    return generateSpec();
}

it('documents the login request body under a clean, framework-agnostic component name', function (): void {
    $doc = generateWithFortifyPlugin();

    $op = $doc['paths']['/login']['post'];
    $ref = $op['requestBody']['content']['application/json']['schema']['$ref'];

    expect($ref)->toBe('#/components/schemas/LoginRequest');

    $schema = $doc['components']['schemas']['LoginRequest'];

    expect($schema['properties'])->toHaveKeys(['email', 'password', 'remember'])
        ->and($schema['required'])->toContain('email', 'password');
});

it('leaks no Fortify/namespace internals into any consumer-visible component key or $ref', function (): void {
    $doc = generateWithFortifyPlugin();

    $serialized = json_encode($doc, JSON_THROW_ON_ERROR);
    $keys = array_keys($doc['components']['schemas'] ?? []);

    expect($keys)->toContain('LoginRequest', 'RegisterRequest', 'ForgotPasswordRequest')
        ->and($serialized)->not->toContain('Fortify')
        ->and($serialized)->not->toContain('\\\\'); // no escaped backslash (namespace separator)

    foreach ($keys as $key) {
        expect($key)->not->toContain('\\');
    }
});

it('emits the stock success body when the response contract is default Fortify', function (): void {
    $doc = generateWithFortifyPlugin();

    $op = $doc['paths']['/forgot-password']['post'];

    expect($op['responses'])->toHaveKey('200')
        ->and($op['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('message');
});

it('drops the success body (status only) when the response contract is rebound', function (): void {
    $doc = generateWithFortifyPlugin(static function ($app): void {
        $app->bind(
            SuccessfulPasswordResetLinkRequestResponse::class,
            static fn(): SuccessfulPasswordResetLinkRequestResponse => new class () implements SuccessfulPasswordResetLinkRequestResponse {
                public function toResponse($request)
                {
                    return response()->json(['x' => 1]);
                }
            },
        );
    });

    $op = $doc['paths']['/forgot-password']['post'];

    expect($op['responses'])->toHaveKey('200')
        ->and($op['responses']['200'])->not->toHaveKey('content'); // body dropped, status retained
});

it('emits a 201 status-only response for register (stock has no body)', function (): void {
    $doc = generateWithFortifyPlugin();

    $op = $doc['paths']['/register']['post'];

    expect($op['responses'])->toHaveKey('201')
        ->and($op['responses']['201'])->not->toHaveKey('content')
        ->and($op['requestBody']['content']['application/json']['schema']['$ref'] ?? null)
        ->toStartWith('#/components/schemas/');
});

it('does not document a route that is not registered', function (): void {
    config(['openapi.plugins' => [FortifyPlugin::class]]);
    Route::post('/login', static fn() => null)->name('login.store');

    $doc = generateSpec();

    expect(array_keys($doc['paths'] ?? []))->toContain('/login')
        ->and($doc['paths'] ?? [])->not->toHaveKey('/register');
});
