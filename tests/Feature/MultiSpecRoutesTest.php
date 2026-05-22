<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

/**
 * Sets app.env to 'local' so DocsController regenerates on every request
 * rather than looking for a pre-built static file.
 */
trait WithLocalEnv
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'local');
    }
}

uses(WithLocalEnv::class)->group('openapi');

it('mounts /api/openapi.yaml for the default spec', function (): void {
    $this->get('/api/openapi.yaml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml');
});

it('returns 404 when route_uri is false for a named spec', function (): void {
    // No route is mounted for 'internal' because its route_uri is false.
    config(['openapi.specs' => ['internal' => ['route_uri' => false]]]);
    $this->get('/api/openapi-internal.yaml')->assertNotFound();
});
