<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Spec;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;

it('holds the spec name, info, servers, tags, match config, paths', function (): void {
    $info = new OA\Info(['title' => 'V1', 'version' => '1.0']);
    $server = new OA\Server(['url' => 'https://v1.example.com']);
    $tag = new OA\Tag(['name' => 'Flights']);

    $spec = new SpecDefinition(
        name: 'v1',
        info: $info,
        servers: [$server],
        tags: [$tag],
        match: ['prefix' => 'api/v1/*'],
        outputPath: '/tmp/openapi-v1.yaml',
        routeUri: 'openapi-v1.yaml',
        playgroundUri: 'docs/v1',
    );

    expect($spec->name)->toBe('v1')
        ->and($spec->info)->toBe($info)
        ->and($spec->servers)->toBe([$server])
        ->and($spec->tags)->toBe([$tag])
        ->and($spec->match)->toBe(['prefix' => 'api/v1/*'])
        ->and($spec->outputPath)->toBe('/tmp/openapi-v1.yaml')
        ->and($spec->routeUri)->toBe('openapi-v1.yaml')
        ->and($spec->playgroundUri)->toBe('docs/v1');
});

it('allows null route_uri and playground_uri to opt out of HTTP serving', function (): void {
    $spec = new SpecDefinition(
        name: 'internal',
        info: new OA\Info(['title' => 'X', 'version' => '1.0']),
        servers: [],
        tags: [],
        match: [],
        outputPath: '/tmp/x.yaml',
        routeUri: null,
        playgroundUri: null,
    );

    expect($spec->routeUri)->toBeNull()
        ->and($spec->playgroundUri)->toBeNull();
});
