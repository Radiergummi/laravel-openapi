<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Spec;

use InvalidArgumentException;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

function makeRegistry(array $rootConfig = [], ?array $specs = null): SpecRegistry
{
    return new SpecRegistry(
        rootInfo: $rootConfig['info'] ?? ['title' => 'API', 'version' => '1.0'],
        rootServers: $rootConfig['servers'] ?? [],
        rootTags: $rootConfig['tags'] ?? [],
        rootOutputPath: $rootConfig['output_path'] ?? '/storage/openapi.yaml',
        rootRouteUri: $rootConfig['routes']['spec']['uri'] ?? 'openapi.yaml',
        rootPlaygroundUri: $rootConfig['routes']['playground']['uri'] ?? 'docs',
        specs: $specs,
        storagePath: '/storage',
    );
}

it('with no `specs` config, returns one default spec from root keys', function (): void {
    $reg = makeRegistry();
    $all = $reg->all();

    expect($all)
        ->toHaveCount(1)
        ->and($all[0]->name)->toBe('default')
        ->and($all[0]->outputPath)->toBe('/storage/openapi.yaml')
        ->and($all[0]->routeUri)->toBe('openapi.yaml')
        ->and($all[0]->playgroundUri)->toBe('docs')
        ->and($all[0]->match)->toBe([]);
});

it('materialises named specs with default output_path / route_uri / playground_uri', function (): void {
    $reg = makeRegistry(specs: [
        'v1' => ['match' => ['prefix' => 'api/v1/*']],
    ]);

    $v1 = $reg->get('v1');

    expect($v1->name)
        ->toBe('v1')
        ->and($v1->outputPath)->toBe('/storage/openapi-v1.yaml')
        ->and($v1->routeUri)->toBe('openapi-v1.yaml')
        ->and($v1->playgroundUri)->toBe('docs/v1')
        ->and($v1->match)->toBe(['prefix' => 'api/v1/*']);
});

it('honours explicit overrides for output_path / route_uri / playground_uri', function (): void {
    $reg = makeRegistry(specs: [
        'v1' => [
            'output_path' => '/custom/path.yaml',
            'route_uri' => 'openapi-versioned.yaml',
            'playground_uri' => 'reference/v1',
        ],
    ]);

    $v1 = $reg->get('v1');

    expect($v1->outputPath)
        ->toBe('/custom/path.yaml')
        ->and($v1->routeUri)->toBe('openapi-versioned.yaml')
        ->and($v1->playgroundUri)->toBe('reference/v1');
});

it('treats false or null route_uri / playground_uri as opt-out (becomes null on the definition)', function (): void {
    $reg = makeRegistry(specs: [
        'internal' => [
            'route_uri' => false,
            'playground_uri' => null,
        ],
    ]);

    $spec = $reg->get('internal');

    expect($spec->routeUri)
        ->toBeNull()
        ->and($spec->playgroundUri)->toBeNull();
});

it('deep-merges per-spec `info` over root info', function (): void {
    $reg = makeRegistry(
        rootConfig: ['info' => ['title' => 'API', 'version' => '1.0', 'description' => 'Root.']],
        specs: ['v1' => ['info' => ['version' => '1.x']]],
    );

    $info = $reg->get('v1')->info;

    expect($info->title)
        ->toBe('API')
        ->and($info->version)->toBe('1.x')
        ->and($info->description)->toBe('Root.');
});

it('#47: deep-merges nested `info` groups, preserving untouched siblings', function (): void {
    $reg = makeRegistry(
        rootConfig: ['info' => [
            'title'   => 'API',
            'version' => '1.0',
            'contact' => ['name' => 'API Team', 'email' => 'team@example.com'],
            'license' => ['name' => 'MIT'],
        ]],
        specs: ['v1' => ['info' => ['contact' => ['email' => 'v1@example.com']]]],
    );

    // Assert via the serialized form — what actually reaches the generated document — since
    // swagger-php keeps the nested groups as arrays the OA\Info object type does not expose.
    $info = json_decode((string) json_encode($reg->get('v1')->info), true);

    // The overridden nested field changes…
    expect($info['contact']['email'])->toBe('v1@example.com')
        // …its sibling under the same group survives…
        ->and($info['contact']['name'])->toBe('API Team')
        // …and an unrelated nested group is untouched.
        ->and($info['license']['name'])->toBe('MIT');
});

it('replaces servers wholesale per-spec', function (): void {
    $reg = makeRegistry(
        rootConfig: ['servers' => [['url' => 'https://root.example.com']]],
        specs: ['v1' => ['servers' => [['url' => 'https://v1.example.com']]]],
    );

    $servers = $reg->get('v1')->servers;

    expect($servers)
        ->toHaveCount(1)
        ->and($servers[0]->url)->toBe('https://v1.example.com');
});

it('reads an explicit `specs.default` entry as overrides on the implicit default', function (): void {
    $reg = makeRegistry(specs: [
        'default' => ['match' => ['prefix' => 'api/*']],
        'v1' => ['match' => ['prefix' => 'api/v1/*']],
    ]);

    $default = $reg->default();

    expect($default->match)
        ->toBe(['prefix' => 'api/*'])
        ->and($default->outputPath)->toBe('/storage/openapi.yaml');     // root key preserved
});

it('throws when getting an unknown spec by name', function (): void {
    $reg = makeRegistry();
    $reg->get('missing');
})->throws(InvalidArgumentException::class, "Spec 'missing' is not defined");
