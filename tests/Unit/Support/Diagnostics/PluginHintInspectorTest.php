<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;
use Radiergummi\OpenApi\Support\Diagnostics\PluginHintInspector;

uses()->group('openapi');

/**
 * @param array<string, true> $presentClasses classes the fake check should report as existing
 */
function pluginHintInspector(array $integrationMap, array $presentClasses): PluginHintInspector
{
    return new PluginHintInspector(
        $integrationMap,
        static fn(string $class): bool => isset($presentClasses[$class]),
    );
}

/**
 * @return list<array{markers: list<string>, package: string, plugin: class-string}>
 */
function fractalAndQueryBuilderMap(): array
{
    return [
        [
            'markers' => ['League\Fractal\Manager', 'Spatie\Fractal\Fractal'],
            'package' => 'league/fractal',
            'plugin' => FractalPlugin::class,
        ],
        [
            'markers' => ['Spatie\QueryBuilder\QueryBuilder'],
            'package' => 'spatie/laravel-query-builder',
            'plugin' => QueryBuilderPlugin::class,
        ],
    ];
}

it('emits a hint when an integration package is installed but its plugin is disabled', function (): void {
    $hints = pluginHintInspector(
        fractalAndQueryBuilderMap(),
        ['League\Fractal\Manager' => true],
    )->hints([]);

    expect($hints)->toHaveCount(1)
        ->and($hints[0])->toContain('league/fractal')
        ->and($hints[0])->toContain('FractalPlugin');
});

it('emits no hint when the plugin is already enabled', function (): void {
    $hints = pluginHintInspector(
        fractalAndQueryBuilderMap(),
        ['League\Fractal\Manager' => true],
    )->hints([FractalPlugin::class]);

    expect($hints)->toBe([]);
});

it('emits no hint when the package is absent', function (): void {
    $hints = pluginHintInspector(
        fractalAndQueryBuilderMap(),
        [],
    )->hints([]);

    expect($hints)->toBe([]);
});

it('treats any of the marker classes as proof the package is installed', function (): void {
    $hints = pluginHintInspector(
        fractalAndQueryBuilderMap(),
        ['Spatie\Fractal\Fractal' => true],
    )->hints([]);

    expect($hints)->toHaveCount(1)
        ->and($hints[0])->toContain('league/fractal');
});

it('emits a single hint when both marker classes for one plugin are present', function (): void {
    $hints = pluginHintInspector(
        fractalAndQueryBuilderMap(),
        ['League\Fractal\Manager' => true, 'Spatie\Fractal\Fractal' => true],
    )->hints([]);

    expect($hints)->toHaveCount(1);
});

it('emits a hint for the query-builder integration', function (): void {
    $hints = pluginHintInspector(
        fractalAndQueryBuilderMap(),
        ['Spatie\QueryBuilder\QueryBuilder' => true],
    )->hints([]);

    expect($hints)->toHaveCount(1)
        ->and($hints[0])->toContain('spatie/laravel-query-builder')
        ->and($hints[0])->toContain('QueryBuilderPlugin');
});

it('emits one hint per installed-but-disabled integration', function (): void {
    $hints = pluginHintInspector(
        fractalAndQueryBuilderMap(),
        [
            'League\Fractal\Manager' => true,
            'Spatie\QueryBuilder\QueryBuilder' => true,
        ],
    )->hints([]);

    expect($hints)->toHaveCount(2)
        ->and($hints[0])->toContain('league/fractal')
        ->and($hints[1])->toContain('spatie/laravel-query-builder');
});
