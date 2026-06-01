<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\Stages\RootStage;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;

uses()->group('openapi');

it('writes openapi version, info, servers, and tags from the spec', function (): void {
    $spec = new SpecDefinition(
        name: 'default',
        info: new OA\Info(['title' => 'API', 'version' => '1.0.0']),
        servers: [new OA\Server(['url' => 'https://api.example.test'])],
        tags: [new OA\Tag(['name' => 'Identity'])],
        match: [],
        outputPath: 'openapi.yaml',
        routeUri: null,
        playgroundUri: null,
    );

    $doc = new OA\OpenApi([]);
    (new RootStage())->apply($doc, new GenerationContext($spec, 'testing'));

    expect($doc->openapi)->toBe('3.1.0')
        ->and($doc->info)->toBe($spec->info)
        ->and($doc->servers)->toBe($spec->servers)
        ->and($doc->tags)->toBe($spec->tags);
});

it('falls back to app.url when no servers are configured', function (): void {
    config()->set('app.url', 'https://fallback.example.test');

    $spec = new SpecDefinition(
        name: 'default',
        info: new OA\Info(['title' => 'API', 'version' => '1.0.0']),
        servers: [],
        tags: [],
        match: [],
        outputPath: 'openapi.yaml',
        routeUri: null,
        playgroundUri: null,
    );

    $doc = new OA\OpenApi([]);
    (new RootStage())->apply($doc, new GenerationContext($spec, 'testing'));

    expect($doc->servers)->toHaveCount(1)
        ->and($doc->servers[0]->url)->toBe('https://fallback.example.test');
});
