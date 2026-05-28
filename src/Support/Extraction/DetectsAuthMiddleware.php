<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use function str_starts_with;

/**
 * Shared helper for detecting `auth` middleware entries in a middleware list.
 *
 * @internal
 */
trait DetectsAuthMiddleware
{
    /**
     * @param list<string> $middleware
     */
    private function hasAuthMiddleware(array $middleware): bool
    {
        return array_any(
            $middleware,
            static fn(string $entry): bool => $entry === 'auth' || str_starts_with($entry, 'auth:'),
        );
    }
}
