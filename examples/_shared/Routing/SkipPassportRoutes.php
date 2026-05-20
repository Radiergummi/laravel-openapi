<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared\Routing;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;

/**
 * Excludes Laravel Passport's own routes from the generated OpenAPI spec. Passport
 * is registered by every flavor's TestbenchBoot so that {@see \Radiergummi\OpenApi\Core\Extractors\SecurityExtractor}
 * can resolve `passport.*` route URLs into the OAuth2 security scheme; we don't want
 * Passport's CRUD endpoints showing up as application surface, though.
 */
final readonly class SkipPassportRoutes implements RouteFilter
{
    public function shouldSkip(Route $route): bool
    {
        $name = $route->getName() ?? '';

        return str_starts_with($name, 'passport.');
    }
}
