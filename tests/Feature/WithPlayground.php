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
