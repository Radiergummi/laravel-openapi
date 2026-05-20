<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\QueryBuilder;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('QueryBuilder');

        config()->set('openapi.plugins', array_merge(
            (array) config('openapi.plugins', []),
            [QueryBuilderPlugin::class],
        ));

        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
