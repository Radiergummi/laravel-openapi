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

        if (
            !is_dir($destination)
            && !mkdir($destination, 0o777, true)
            && !is_dir($destination)
        ) {
            throw new RuntimeException("Failed to create fixture destination: {$destination}");
        }

        foreach ((array) glob($source . '/*') as $file) {
            $target = $destination . '/' . basename((string) $file);

            if (!copy((string) $file, $target)) {
                throw new RuntimeException("Failed to copy fixture {$file} -> {$target}");
            }
        }
    }
}
