<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

uses()->group('openapi');

/**
 * Injects openapi.specs into the application config before the service provider boots.
 * defineEnvironment() runs after RegisterProviders but before BootProviders, so registerRoutes()
 * sees the v1 spec and mounts its route.
 */
trait WithV1SpecConfig
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('openapi.specs', [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ]);
    }
}

uses(WithV1SpecConfig::class);

it('mounts named-spec routes when specs.X.route_uri resolves', function (): void {
    // The test exercises route mounting, not generation: drop a placeholder file at the spec's
    // resolved output path so DocsController's non-local-env branch can serve it.
    $path = storage_path('openapi-v1.yaml');
    file_put_contents($path, "openapi: 3.1.0\n");

    try {
        $this->get('/api/openapi-v1.yaml')->assertOk();
    } finally {
        @unlink($path);
    }
});
