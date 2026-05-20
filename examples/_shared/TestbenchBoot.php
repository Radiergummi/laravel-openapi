<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared;

use Examples\Shared\Database\Seeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Laravel\Passport\PassportServiceProvider;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Radiergummi\OpenApi\OpenApiServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * Boots a real Laravel container (via Testbench) configured for one example
 * flavor. Used by the `examples/generate.php` runner and by `ExamplesTest`.
 */
final class TestbenchBoot
{
    /**
     * @param class-string $serviceProvider The flavor's ExampleServiceProvider.
     */
    public static function boot(string $serviceProvider): Application
    {
        // Pin APP_ENV to `testing` before Testbench boots. This (a) makes the OpenApi service
        // provider skip the dev-only playground route (which would otherwise show up in
        // generated specs when run via the `composer examples:*` scripts but not when the
        // suite is exercised from tests), and (b) lets spatie/laravel-data short-circuit its
        // structure cache (it gates on `runningUnitTests()`, which keys off env=testing).
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');

        // Use Testbench's default skeleton (which has bootstrap/cache, storage, etc.) as
        // the base path. Migrations are loaded by absolute path so the basePath choice
        // does not matter for them.
        $app = TestbenchApplication::create(
            basePath: null,
            options: ['enables-package-discoveries' => false],
        );

        $app->register(LaravelDataServiceProvider::class);
        $app->register(PassportServiceProvider::class);
        $app->register(OpenApiServiceProvider::class);
        $app->register($serviceProvider);

        $app->make(Kernel::class)->bootstrap();

        // In-memory SQLite + run the shared migration + seed.
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Keep caches in memory so spatie/laravel-data's structure-cache doesn't try to write
        // to the (non-existent) `cache` database table that Testbench would otherwise resolve.
        config()->set('cache.default', 'array');
        config()->set('data.structure_caching.enabled', false);

        $app->make(Kernel::class)->call('migrate', [
            '--path'     => dirname(__DIR__) . '/_shared/Database/migrations',
            '--realpath' => true,
            '--database' => 'testing',
        ]);

        Seeder::run();

        return $app;
    }
}
