<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\CookieParamFixtureController;

uses()->group('openapi');

beforeEach(function (): void {
    Route::get('/oa-fixture/cookie/simple', [CookieParamFixtureController::class, 'simpleAction']);
    Route::get('/oa-fixture/cookie/required', [CookieParamFixtureController::class, 'requiredAction']);
    Route::get('/oa-fixture/cookie/enum', [CookieParamFixtureController::class, 'enumAction']);
    Route::get('/oa-fixture/cookie/described', [CookieParamFixtureController::class, 'describedAction']);
    Route::get('/oa-fixture/cookie/multiple', [CookieParamFixtureController::class, 'multipleAction']);
    Route::get('/oa-fixture/cookie/override', [CookieParamFixtureController::class, 'overrideAction']);
    Route::get('/oa-fixture/cookie/case-variant', [CookieParamFixtureController::class, 'caseVariantAction']);

    $this->spec = generateSpec();
});

/**
 * @param array<int, array<string, mixed>> $parameters
 *
 * @return array<string, array<string, mixed>>
 */
function cookieParameters(array $parameters): array
{
    return array_column(
        array_filter(
            $parameters,
            static fn(array $p): bool => ($p['in'] ?? null) === 'cookie',
        ),
        null,
        'name',
    );
}

it('emits a single optional, string-typed cookie parameter', function (): void {
    $cookies = cookieParameters($this->spec['paths']['/oa-fixture/cookie/simple']['get']['parameters'] ?? []);

    expect($cookies)
        ->toHaveKey('session')
        ->and($cookies['session']['in'])->toBe('cookie')
        ->and($cookies['session']['required'])->toBeFalse()
        ->and($cookies['session']['schema']['type'])->toBe('string');
});

it('honors required and an explicit type', function (): void {
    $cookies = cookieParameters($this->spec['paths']['/oa-fixture/cookie/required']['get']['parameters'] ?? []);

    expect($cookies['uid']['required'])
        ->toBeTrue()
        ->and($cookies['uid']['schema']['type'])->toBe('integer');
});

it('forwards a backed-enum class-string into schema.enum', function (): void {
    $cookies = cookieParameters($this->spec['paths']['/oa-fixture/cookie/enum']['get']['parameters'] ?? []);

    expect($cookies['theme']['schema']['enum'])
        ->toBe(['active', 'archived', 'draft'])
        ->and($cookies['theme']['schema']['type'])->toBe('string');
});

it('forwards description and example', function (): void {
    $cookies = cookieParameters($this->spec['paths']['/oa-fixture/cookie/described']['get']['parameters'] ?? []);

    expect($cookies['locale']['schema']['description'])
        ->toBe('Preferred UI locale.')
        ->and($cookies['locale']['schema']['example'])->toBe('en-US');
});

it('emits one entry per cookie, declaration order preserved', function (): void {
    // The class-level `tracking` cookie precedes the two method-level entries; assert the
    // method-level pair keeps its declaration order.
    $names = array_column(
        array_filter(
            $this->spec['paths']['/oa-fixture/cookie/multiple']['get']['parameters'] ?? [],
            static fn(array $p): bool => ($p['in'] ?? null) === 'cookie',
        ),
        'name',
    );

    $cookies = cookieParameters($this->spec['paths']['/oa-fixture/cookie/multiple']['get']['parameters'] ?? []);

    expect(array_values(array_intersect($names, ['first', 'second'])))
        ->toBe(['first', 'second'])
        ->and($cookies['second']['required'])->toBeTrue()
        ->and($cookies['first']['required'])->toBeFalse();
});

it('lets a method-level cookie override the class-level entry of the same name', function (): void {
    $cookies = cookieParameters($this->spec['paths']['/oa-fixture/cookie/override']['get']['parameters'] ?? []);

    // Class-level `tracking` is optional; the method-level one wins and is required.
    expect($cookies)
        ->toHaveCount(1)
        ->and($cookies['tracking']['required'])->toBeTrue();
});

it('keeps case-differing cookie names as distinct cookies (RFC 6265 case-sensitivity)', function (): void {
    $cookies = cookieParameters($this->spec['paths']['/oa-fixture/cookie/case-variant']['get']['parameters'] ?? []);

    // The class-level `tracking` cookie rides along; the point is that `Session` and `session`
    // stay two separate entries rather than collapsing.
    expect($cookies)
        ->toHaveKey('Session')
        ->and($cookies)->toHaveKey('session');
});

it('never guesses cookies when the action carries no #[CookieParam]', function (): void {
    Route::get('/oa-fixture/cookie/bare', static fn(): array => []);

    $cookies = cookieParameters(
        generateSpec()['paths']['/oa-fixture/cookie/bare']['get']['parameters'] ?? [],
    );

    expect($cookies)->toBe([]);
});
