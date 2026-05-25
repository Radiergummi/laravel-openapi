<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Radiergummi\OpenApi\Console\ClearCommand;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Core\Errors\LaravelEnvelope;
use Radiergummi\OpenApi\Core\Errors\NoneEnvelope;
use Radiergummi\OpenApi\Core\Extractors\SecurityExtractor;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Generator\OperationBuilder;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Routing\ReturnTypeExtractor;

uses()->group('openapi');

dataset('scoped_bindings', [
    OpenApiGenerator::class       => [OpenApiGenerator::class],
    OperationBuilder::class       => [OperationBuilder::class],
    ComponentSchemaRegistry::class => [ComponentSchemaRegistry::class],
    SecurityExtractor::class      => [SecurityExtractor::class],
    ReturnTypeExtractor::class    => [ReturnTypeExtractor::class],
]);

it('registers key pipeline services as scoped bindings', function (string $class): void {
    $first = app($class);
    $secondSameScope = app($class);

    // Within the same scope, the binding must behave like a singleton.
    expect($secondSameScope)->toBe($first);

    // Forgetting scoped instances must yield a fresh instance — proves the binding is `scoped`,
    // not `singleton` (singletons survive forgetScopedInstances()).
    app()->forgetScopedInstances();
    $afterReset = app($class);

    expect($afterReset)->not->toBe($first);
})->with('scoped_bindings');

it('registers the openapi Artisan commands', function (): void {
    $kernel = app(Kernel::class);
    $all = $kernel->all();

    expect($all)
        ->toHaveKey('openapi:generate')
        ->toHaveKey('openapi:lint')
        ->toHaveKey('openapi:clear')
        ->and($all['openapi:generate'])->toBeInstanceOf(GenerateCommand::class)
        ->and($all['openapi:lint'])->toBeInstanceOf(LintCommand::class)
        ->and($all['openapi:clear'])->toBeInstanceOf(ClearCommand::class);
});

it('declares the config file as publishable under the openapi-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(null, 'openapi-config');

    expect($paths)->not->toBeEmpty();

    $sourcePaths = array_keys($paths);
    $matchesSource = array_filter(
        $sourcePaths,
        static fn(string $path): bool => str_ends_with($path, 'config/openapi.php'),
    );

    expect($matchesSource)->not->toBeEmpty();

    foreach ($paths as $target) {
        expect($target)->toEndWith('openapi.php');
    }
});

it('registers the configured envelope resolver', function (): void {
    config()->set('openapi.error_envelope', 'laravel');

    $registry = app(OpenApiRegistry::class);

    expect($registry->errorResponseResolvers())->toContain(LaravelEnvelope::class);
});

it('defaults to NoneEnvelope when no envelope is configured', function (): void {
    config()->set('openapi.error_envelope', 'none');

    $registry = app(OpenApiRegistry::class);

    expect($registry->errorResponseResolvers())->toContain(NoneEnvelope::class);
});

it('throws InvalidArgumentException on a typoed preset name', function (): void {
    config()->set('openapi.error_envelope', 'larvel');

    expect(fn () => app(OpenApiRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'Unknown error_envelope "larvel"');
});

it('throws InvalidArgumentException when a custom FQCN does not implement the resolver', function (): void {
    config()->set('openapi.error_envelope', stdClass::class);

    expect(fn () => app(OpenApiRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'does not implement');
});
