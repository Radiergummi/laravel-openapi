<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Examples\Shared\TestbenchBoot;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Yaml\Yaml;

/*
 * Per-flavor verification:
 *   1. Boot Testbench with the flavor's ServiceProvider.
 *   2. Generate the OpenAPI document to a temp file.
 *   3. Assert it byte-matches the committed snapshot.
 *   4. Assert it validates as OpenAPI 3.1 (swagger-php Analysis::validate()).
 *   5. Assert `openapi:lint` reports zero findings.
 */
dataset('flavors', [
    'vanilla'       => [Examples\Vanilla\ExampleServiceProvider::class,       'vanilla'],
    'form-requests' => [Examples\FormRequests\ExampleServiceProvider::class,  'form-requests'],
    'spatie-data'   => [Examples\SpatieData\ExampleServiceProvider::class,    'spatie-data'],
    'query-builder' => [Examples\QueryBuilder\ExampleServiceProvider::class,  'query-builder'],
    'combined'      => [Examples\Combined\ExampleServiceProvider::class,      'combined'],
]);

it('produces a snapshot that matches the committed yaml', function (string $serviceProvider, string $flavor): void {
    $app = TestbenchBoot::boot($serviceProvider);
    $snapshot = dirname(__DIR__, 2) . "/examples/{$flavor}/openapi.yaml";
    $temp = tempnam(sys_get_temp_dir(), 'openapi-');

    try {
        $status = $app->make(Kernel::class)->call('openapi:generate', [
            'path'     => $temp,
            '--format' => 'yaml',
        ]);

        expect($status)->toBe(0)
            ->and(file_get_contents($temp))->toBe(file_get_contents($snapshot));
    } finally {
        @unlink($temp);
    }
})->with('flavors');

it('produces a valid OpenAPI 3.1 document', function (string $serviceProvider, string $flavor): void {
    $snapshot = dirname(__DIR__, 2) . "/examples/{$flavor}/openapi.yaml";
    $parsed = Yaml::parseFile($snapshot);

    expect($parsed['openapi'])->toStartWith('3.1')
        ->and($parsed)->toHaveKeys(['info', 'paths']);
})->with('flavors');

it('lints clean', function (string $serviceProvider, string $flavor): void {
    $app = TestbenchBoot::boot($serviceProvider);
    config()->set('openapi.output_path', dirname(__DIR__, 2) . "/examples/{$flavor}/openapi.yaml");

    $status = $app->make(Kernel::class)->call('openapi:lint');

    expect($status)->toBe(0);
})->with('flavors');
