<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

/**
 * Boots with the playground route mounted and the spec served on every request
 * (app.env = local), so the renderer choice can be exercised end-to-end.
 */
trait WithPlayground
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'local');
        $app['config']->set('openapi.routes.playground.enabled', true);
    }
}

uses(WithPlayground::class)->group('openapi');

it('renders the Scalar shell by default, pointed at the spec endpoint', function (): void {
    $response = $this->get('/api/docs')->assertOk();

    expect($response->getContent())
        ->toContain('@scalar/api-reference')
        ->toContain(route('openapi.spec'));
});

it('renders the Swagger UI shell when the renderer is swagger-ui', function (): void {
    config(['openapi.routes.playground.renderer' => 'swagger-ui']);

    $response = $this->get('/api/docs')->assertOk();

    expect($response->getContent())
        ->toContain('SwaggerUIBundle')
        ->toContain('swagger-ui')
        ->toContain(route('openapi.spec'))
        ->not->toContain('@scalar/api-reference');
});

it('falls back to the Scalar shell for an unknown renderer value', function (): void {
    config(['openapi.routes.playground.renderer' => 'nonsense']);

    expect($this->get('/api/docs')->assertOk()->getContent())
        ->toContain('@scalar/api-reference');
});
