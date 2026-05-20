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
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Radiergummi\OpenApi\OpenApiServiceProvider;

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
        // Use Testbench's default skeleton (which has bootstrap/cache, storage, etc.) as
        // the base path. Migrations are loaded by absolute path so the basePath choice
        // does not matter for them.
        $app = TestbenchApplication::create(
            basePath: null,
            options: ['enables-package-discoveries' => false],
        );

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

        $app->make(Kernel::class)->call('migrate', [
            '--path'     => dirname(__DIR__) . '/_shared/Database/migrations',
            '--realpath' => true,
            '--database' => 'testing',
        ]);

        Seeder::run();

        return $app;
    }
}
