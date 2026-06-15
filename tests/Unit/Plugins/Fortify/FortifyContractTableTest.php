<?php

declare(strict_types=1);

use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\Fortify\Support\FortifyContractTable;

use function array_map;

uses()->group('openapi', 'plugin:fortify');

it('exposes an entry for each v1 core-auth route name', function (): void {
    $names = ['login.store', 'logout', 'register.store', 'password.email', 'password.update',
        'password.confirm.store', 'password.confirmation', 'user-password.update',
        'user-profile-information.update'];

    foreach ($names as $name) {
        expect(FortifyContractTable::for($name))->not->toBeNull();
    }

    expect(FortifyContractTable::for('two-factor.login'))->toBeNull() // deferred, not in v1
        ->and(FortifyContractTable::for('login'))->toBeNull(); // GET view route, not the action
});

it('returns a request schema and response contract for login', function (): void {
    $entry = FortifyContractTable::for('login.store');

    expect($entry->requestSchema)->toBeInstanceOf(OA\Schema::class)
        ->and($entry->requestSchemaName)->toBe('LoginRequest') // clean, framework-agnostic name
        ->and($entry->responseContract)->toBe(LoginResponse::class)
        ->and($entry->successStatus)->toBe(200)
        ->and($entry->successSchema)->toBeInstanceOf(OA\Schema::class); // {two_factor: boolean}
});

it('names the login identifier field from Fortify::username() rather than hardcoding it', function (): void {
    $default = array_map(
        static fn(OA\Property $p): string => (string) $p->property,
        FortifyContractTable::for('login.store')->requestSchema->properties,
    );
    expect($default)->toContain('email'); // default config

    config(['fortify.username' => 'login_id']);

    $custom = array_map(
        static fn(OA\Property $p): string => (string) $p->property,
        FortifyContractTable::for('login.store')->requestSchema->properties,
    );

    expect($custom)->toContain('login_id')
        ->and($custom)->not->toContain('email')
        ->and(Fortify::username())->toBe('login_id');
});

it('carries no request body for the body-less endpoints', function (): void {
    expect(FortifyContractTable::for('logout')->requestSchema)->toBeNull()
        ->and(FortifyContractTable::for('logout')->successStatus)->toBe(204)
        ->and(FortifyContractTable::for('password.confirmation')->requestSchema)->toBeNull()
        ->and(FortifyContractTable::for('password.confirmation')->responseContract)->toBeNull();
});
