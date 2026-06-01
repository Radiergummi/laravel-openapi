<?php

declare(strict_types=1);

namespace Examples\Fractal;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('Fractal');

        config()->set('openapi.plugins', array_merge(
            (array) config('openapi.plugins', []),
            [FractalPlugin::class],
        ));

        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
