<?php

declare(strict_types=1);

namespace Examples\SwaggerPhp;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('SwaggerPhp');

        config()->set('openapi.plugins', [
            ...(array) config('openapi.plugins', []),
            SwaggerPhpPlugin::class,
        ]);

        // The scanner defaults to app_path(); point it at this flavor's annotated sources instead.
        $this->app->scoped(
            AuthoredAnnotationScanner::class,
            static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
                [__DIR__],
                $app->make(LoggerInterface::class),
            ),
        );

        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
