<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Vanilla;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('Vanilla');
        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
