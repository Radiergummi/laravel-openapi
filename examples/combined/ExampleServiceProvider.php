<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;
use Radiergummi\OpenApi\Support\Generator\ExampleFileLoader;
use RuntimeException;

use function assert;
use function base_path;
use function basename;
use function copy;
use function glob;
use function is_dir;
use function mkdir;

/**
 * Wires the combined flavor into the host application.
 *
 * Mirrors the QueryBuilder flavor's pattern for opting into the QueryBuilder plugin, and
 * additionally mirrors the on-disk example payloads into the Testbench skeleton's base path so that
 * {@see ExampleFileLoader} — which resolves `file:` arguments via `base_path()` — can find them at
 * spec-generation time.
 *
 * Registers a `bearer` JWT security scheme via `openapi.security_schemes` and points the write
 * endpoints at it through `#[Security(…, scheme: 'bearer')]`, showcasing the config-driven
 * scheme-registration path.
 */
final class ExampleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('Combined');

        config()->set(
            'openapi.plugins',
            array_merge(
                (array) config('openapi.plugins', []),
                [QueryBuilderPlugin::class],
            ),
        );

        config()->set('openapi.security_schemes', [
            'bearer' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'Bearer JWT issued by the auth service.',
            ],
        ]);

        $this->mirrorExamplePayloads();

        assert($router instanceof Router);
        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }

    /**
     * Copy the flavor's example payload files into the host app's `base_path()` so that
     * `#[ResponseExample(file: 'examples/combined/example_payloads/…')]` resolves at generation
     * time. {@see ExampleFileLoader} routes relative paths through `base_path()`.
     *
     * @throws RuntimeException
     */
    private function mirrorExamplePayloads(): void
    {
        $source = __DIR__ . '/example_payloads';
        $destination = base_path('examples/combined/example_payloads');

        if (!is_dir($source)) {
            return;
        }

        if (
            !is_dir($destination)
            && !mkdir($destination, 0o777, true)
            && !is_dir($destination)
        ) {
            throw new RuntimeException(
                sprintf('Directory "%s" was not created', $destination),
            );
        }

        foreach ((array) glob($source . '/*') as $file) {
            $path = (string) $file;
            copy($path, $destination . '/' . basename($path));
        }
    }
}
