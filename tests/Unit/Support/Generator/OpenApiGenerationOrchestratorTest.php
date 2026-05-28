<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Tests\Fixtures\FileUploadFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\SimpleFormRequest;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi');

class OrchestratorSimpleController extends Controller
{
    public function store(SimpleFormRequest $request): array
    {
        return [];
    }
}

class OrchestratorFileController extends Controller
{
    public function upload(FileUploadFormRequest $request): array
    {
        return [];
    }
}

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

it('generateAll produces specs with disjoint component schemas (no cross-contamination)', function (): void {
    // Two routes, two distinct FormRequests, two specs partitioned by URL prefix. After a
    // single generateAll() call the component schema registry must not have leaked schemas
    // from one spec into the other — that would mean per-run state survived the reset.
    Route::post('/multispec/v1/simple', [OrchestratorSimpleController::class, 'store']);
    Route::post('/multispec/v2/upload', [OrchestratorFileController::class, 'upload']);

    config(['openapi.specs' => [
        'v1' => ['match' => ['prefix' => 'multispec/v1/*']],
        'v2' => ['match' => ['prefix' => 'multispec/v2/*']],
    ]]);

    app()->forgetScopedInstances();

    $documents = app(OpenApiGenerationOrchestrator::class)->generateAll('testing');

    $v1Schemas = array_keys(Yaml::parse($documents['v1']->toYaml())['components']['schemas'] ?? []);
    $v2Schemas = array_keys(Yaml::parse($documents['v2']->toYaml())['components']['schemas'] ?? []);

    expect($v1Schemas)->toContain('SimpleFormRequest')
        ->and($v1Schemas)->not->toContain('FileUploadFormRequest')
        ->and($v2Schemas)->toContain('FileUploadFormRequest')
        ->and($v2Schemas)->not->toContain('SimpleFormRequest');
});
