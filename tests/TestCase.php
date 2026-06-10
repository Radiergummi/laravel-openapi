<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests;

use Illuminate\Foundation\Application;
use Laravel\Passport\PassportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Radiergummi\OpenApi\OpenApiServiceProvider;
use Radiergummi\OpenApi\Support\Generator\ExampleFileLoader;
use RuntimeException;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            PassportServiceProvider::class,
            OpenApiServiceProvider::class,
        ];
    }

    /**
     * Testbench-core 10 (paired with Laravel 12) defaults `filesystems.disks.local.serve` to true,
     * which makes Laravel's FilesystemServiceProvider register two extra `storage/{path}` routes
     * that are absent under testbench 11 (Laravel 13). Force the setting off so generation
     * snapshots and lint expectations are stable across both cells of the CI matrix.
     *
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.local.serve', false);
    }

    /**
     * Mirror the package's example-payload fixtures into the Testbench skeleton app so that
     * `base_path()`-relative resources resolve.
     *
     * Code paths such as {@see ExampleFileLoader} resolve `file:` example payloads relative to the
     * host-app root. In the test environment the host app is the Testbench skeleton, so the package
     * fixtures must be made available there.
     *
     * @throws RuntimeException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $source = dirname(__DIR__) . '/tests/Fixtures/OpenApi/example_payloads';

        if (!is_dir($source)) {
            throw new RuntimeException("Test fixture source missing: {$source}");
        }

        $destination = base_path('tests/Fixtures/OpenApi/example_payloads');

        // Under Pest's parallel runner many workers race this same path. Suppress the mkdir error
        // (a worker that loses the race gets false even though the directory now exists), clear the
        // per-process stat cache the recursive mkdir can leave stale, then re-check authoritatively.
        if (!is_dir($destination)) {
            @mkdir($destination, 0o777, true);
            clearstatcache(true, $destination);

            if (!is_dir($destination)) {
                throw new RuntimeException("Failed to create fixture destination: {$destination}");
            }
        }

        foreach ((array) glob($source . '/*') as $file) {
            $target = $destination . '/' . basename((string) $file);

            // Idempotent + atomic: skip a fixture already mirrored, and copy via a process-unique
            // temp + rename so a concurrent worker never reads a half-written file.
            if (is_file($target)) {
                continue;
            }

            $temp = $target . '.' . getmypid() . '.tmp';

            if (!copy((string) $file, $temp) || !rename($temp, $target)) {
                @unlink($temp);

                throw new RuntimeException("Failed to copy fixture {$file} -> {$target}");
            }
        }
    }
}
