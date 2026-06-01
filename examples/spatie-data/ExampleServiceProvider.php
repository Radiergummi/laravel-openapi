<?php

declare(strict_types=1);

namespace Examples\SpatieData;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('SpatieData');
        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
