<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Spec;

use Radiergummi\OpenApi\Core\Spec\SpecMatcher;

beforeEach(function (): void {
    $this->matcher = new SpecMatcher();
});

it('matches empty / missing config — matches everything', function (): void {
    expect($this->matcher->matches(uri: 'api/anything', middleware: [], controller: null, match: []))->toBeTrue();
});

it('matches a single prefix as fnmatch glob', function (): void {
    $match = ['prefix' => 'api/v1/*'];

    expect($this->matcher->matches('api/v1/flights', [], null, $match))->toBeTrue()
        ->and($this->matcher->matches('api/v2/flights', [], null, $match))->toBeFalse();
});

it('matches list of prefixes with OR semantics', function (): void {
    $match = ['prefix' => ['api/v1/*', 'api/v1b/*']];

    expect($this->matcher->matches('api/v1b/x', [], null, $match))->toBeTrue();
});

it('matches middleware literally or by prefix-before-colon', function (): void {
    $match = ['middleware' => 'auth'];

    expect($this->matcher->matches('x', ['auth:api'], null, $match))->toBeTrue()
        ->and($this->matcher->matches('x', ['auth'], null, $match))->toBeTrue()
        ->and($this->matcher->matches('x', ['throttle'], null, $match))->toBeFalse();
});

it('matches a middleware literal with its own colon-suffix', function (): void {
    $match = ['middleware' => 'auth:partner'];

    expect($this->matcher->matches('x', ['auth:partner'], null, $match))->toBeTrue()
        ->and($this->matcher->matches('x', ['auth:api'], null, $match))->toBeFalse();
});

it('matches namespace prefix on controller FQCN', function (): void {
    $match = ['namespace' => 'App\\Http\\Controllers\\V1\\'];

    expect($this->matcher->matches('x', [], 'App\\Http\\Controllers\\V1\\FlightController', $match))->toBeTrue()
        ->and($this->matcher->matches('x', [], 'App\\Http\\Controllers\\V2\\FlightController', $match))->toBeFalse()
        ->and($this->matcher->matches('x', [], null, $match))->toBeFalse();
});

it('ANDs the three keys — every present key must match', function (): void {
    $match = [
        'prefix'     => 'api/v1/*',
        'middleware' => 'auth:partner',
    ];

    expect($this->matcher->matches('api/v1/flights', ['auth:partner'], null, $match))->toBeTrue()
        ->and($this->matcher->matches('api/v1/flights', ['auth:api'], null, $match))->toBeFalse()
        ->and($this->matcher->matches('api/v2/flights', ['auth:partner'], null, $match))->toBeFalse();
});

it('ignores unknown keys', function (): void {
    $match = ['unknown' => 'whatever', 'prefix' => 'api/v1/*'];

    expect($this->matcher->matches('api/v1/x', [], null, $match))->toBeTrue();
});
