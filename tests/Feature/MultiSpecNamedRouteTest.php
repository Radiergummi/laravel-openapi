<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

uses()->group('openapi');

/**
 * Injects openapi.specs into the application config before the service provider
 * boots. defineEnvironment() runs after RegisterProviders but before BootProviders,
 * so registerRoutes() sees the v1 spec and mounts its route.
 */
trait WithV1SpecConfig
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'local');
        $app['config']->set('openapi.specs', [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ]);
    }
}

uses(WithV1SpecConfig::class);

it('mounts named-spec routes when specs.X.route_uri resolves', function (): void {
    $this->get('/api/openapi-v1.yaml')->assertOk();
});
