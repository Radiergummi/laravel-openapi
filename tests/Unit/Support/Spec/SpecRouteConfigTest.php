<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Spec;

use Radiergummi\OpenApi\Support\Spec\SpecRouteConfig;

function makeRouteConfig(): SpecRouteConfig
{
    return new SpecRouteConfig(
        rootRouteUri: 'openapi.yaml',
        rootPlaygroundUri: 'docs',
    );
}

it('applies the root default when the default spec has no override', function (): void {
    $config = makeRouteConfig();

    expect($config->routeUri('default', []))
        ->toBe('openapi.yaml')
        ->and($config->playgroundUri('default', []))->toBe('docs');
});

it('applies the per-name default when a named spec has no override', function (): void {
    $config = makeRouteConfig();

    expect($config->routeUri('v1', []))
        ->toBe('openapi-v1.yaml')
        ->and($config->playgroundUri('v1', []))->toBe('docs/v1');
});

it('lets a per-spec override win over the default', function (): void {
    $config = makeRouteConfig();
    $overrides = [
        'route_uri' => 'custom.yaml',
        'playground_uri' => 'reference/v1',
    ];

    expect($config->routeUri('v1', $overrides))
        ->toBe('custom.yaml')
        ->and($config->playgroundUri('v1', $overrides))->toBe('reference/v1');
});

it('propagates a `false` override as opt-out for both URIs', function (): void {
    $config = makeRouteConfig();
    $overrides = [
        'route_uri' => false,
        'playground_uri' => false,
    ];

    expect($config->routeUri('v1', $overrides))
        ->toBeFalse()
        ->and($config->playgroundUri('v1', $overrides))->toBeFalse();
});

it('treats a `null` override as opt-out for both URIs', function (): void {
    $config = makeRouteConfig();
    $overrides = [
        'route_uri' => null,
        'playground_uri' => null,
    ];

    expect($config->routeUri('v1', $overrides))
        ->toBeFalse()
        ->and($config->playgroundUri('v1', $overrides))->toBeFalse();
});
