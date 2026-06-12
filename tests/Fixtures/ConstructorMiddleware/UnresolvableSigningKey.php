<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware;

/**
 * Deliberately unbound constructor dependency: any controller requiring it cannot be
 * instantiated by the container, so `Route::gatherMiddleware()` throws — the scenario the
 * static constructor-middleware scan exists for.
 */
interface UnresolvableSigningKey {}
