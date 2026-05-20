<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;

use function base_path;
use function basename;
use function copy;
use function glob;
use function is_dir;
use function mkdir;

/**
 * Wires the combined flavor into the host application.
 *
 * Mirrors the QueryBuilder flavor's pattern for opting into the QueryBuilder
 * plugin, and additionally mirrors the on-disk example payloads into the
 * Testbench skeleton's base path so that
 * {@see \Radiergummi\OpenApi\Core\Generator\ExampleFileLoader} — which
 * resolves `file:` arguments via `base_path()` — can find them at
 * spec-generation time.
 *
 * Security-scheme caveat: Core currently emits the OAuth2 schemes hard-coded
 * by {@see \Radiergummi\OpenApi\Core\Extractors\SecurityExtractor::buildSchemes()};
 * there is no config hook for additional schemes such as bearer/JWT. The
 * flavor therefore uses `#[Security([…scopes])]` against those default schemes
 * and `#[PublicEndpoint]` for anonymous routes. Allowing arbitrary
 * config-registered security schemes is tracked separately.
 */
final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('Combined');

        // The QueryBuilder plugin ships disabled; opt in.
        config()->set('openapi.plugins', array_merge(
            (array) config('openapi.plugins', []),
            [QueryBuilderPlugin::class],
        ));

        $this->mirrorExamplePayloads();

        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }

    /**
     * Copy the flavor's example payload files into the host app's `base_path()`
     * so that `#[ResponseExample(file: 'examples/combined/example_payloads/…')]`
     * resolves at generation time. {@see \Radiergummi\OpenApi\Core\Generator\ExampleFileLoader}
     * routes relative paths through `base_path()`.
     */
    private function mirrorExamplePayloads(): void
    {
        $source = __DIR__ . '/example_payloads';
        $destination = base_path('examples/combined/example_payloads');

        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($destination)) {
            @mkdir($destination, 0o777, true);
        }

        foreach ((array) glob($source . '/*') as $file) {
            $path = (string) $file;
            @copy($path, $destination . '/' . basename($path));
        }
    }
}
