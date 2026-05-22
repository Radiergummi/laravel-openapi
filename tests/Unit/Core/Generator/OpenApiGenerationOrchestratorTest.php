<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator;

uses()->group('openapi');

it('generateAll returns one document per defined spec, keyed by spec name', function (): void {
    config(['openapi.specs' => [
        'v1' => ['match' => ['prefix' => 'api/v1/*']],
    ]]);

    app()->forgetScopedInstances();

    $documents = app(OpenApiGenerationOrchestrator::class)->generateAll('testing');

    expect($documents)
        ->toBeArray()
        ->toHaveKeys(['default', 'v1'])
        ->and($documents['default'])->toBeInstanceOf(OA\OpenApi::class)
        ->and($documents['v1'])->toBeInstanceOf(OA\OpenApi::class);
});

it('generateOne returns the document for the named spec with its resolved info', function (): void {
    config(['openapi.specs' => [
        'v1' => ['info' => ['title' => 'V1 API']],
    ]]);

    app()->forgetScopedInstances();

    $document = app(OpenApiGenerationOrchestrator::class)->generateOne('v1', 'testing');

    expect($document)->toBeInstanceOf(OA\OpenApi::class)
        ->and($document->info->title)->toBe('V1 API');
});

it('forgetScopedInstances yields a fresh ComponentSchemaRegistry on each resolution', function (): void {
    // This test verifies the mechanism that generateForSpec relies on:
    // forgetScopedInstances() causes the container to hand out new scoped instances.
    $container = app();

    $first = $container->make(ComponentSchemaRegistry::class);

    $container->forgetScopedInstances();

    $second = $container->make(ComponentSchemaRegistry::class);

    expect($first)->not->toBe($second);
});
