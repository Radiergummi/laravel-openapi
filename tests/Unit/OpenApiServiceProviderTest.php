<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Radiergummi\OpenApi\Console\ClearCommand;
use Radiergummi\OpenApi\Console\DiffConfigCommand;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Console\WhyCommand;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\LaravelEnvelope;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\NoneEnvelope;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Support\Extraction\SecurityExtractor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;

uses()->group('openapi');

dataset('scoped_bindings', [
    OpenApiGenerator::class => [OpenApiGenerator::class],
    OperationBuilder::class => [OperationBuilder::class],
    ComponentSchemaRegistry::class => [ComponentSchemaRegistry::class],
    SecurityExtractor::class => [SecurityExtractor::class],
    ReturnTypeExtractor::class => [ReturnTypeExtractor::class],
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
        ->toHaveKey('openapi:why')
        ->toHaveKey('openapi:diff:config')
        ->and($all['openapi:generate'])->toBeInstanceOf(GenerateCommand::class)
        ->and($all['openapi:lint'])->toBeInstanceOf(LintCommand::class)
        ->and($all['openapi:clear'])->toBeInstanceOf(ClearCommand::class)
        ->and($all['openapi:why'])->toBeInstanceOf(WhyCommand::class)
        ->and($all['openapi:diff:config'])->toBeInstanceOf(DiffConfigCommand::class);
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

    expect($registry->errorResponseResolvers)->toContain(LaravelEnvelope::class);
});

it('defaults to NoneEnvelope when no envelope is configured', function (): void {
    config()->set('openapi.error_envelope', 'none');

    $registry = app(OpenApiRegistry::class);

    expect($registry->errorResponseResolvers)->toContain(NoneEnvelope::class);
});

it('throws InvalidArgumentException on a typoed preset name', function (): void {
    config()->set('openapi.error_envelope', 'larvel');

    expect(fn() => app(OpenApiRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'Unknown error_envelope "larvel"');
});

it('throws InvalidArgumentException when a custom FQCN does not implement the resolver', function (): void {
    config()->set('openapi.error_envelope', stdClass::class);

    expect(fn() => app(OpenApiRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'does not implement');
});
