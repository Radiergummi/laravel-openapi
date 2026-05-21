<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing\Filters;

use Illuminate\Routing\Route;

use function str_starts_with;

/**
 * Excludes Laravel Passport's own routes from the generated OpenAPI spec.
 *
 * Passport registers a dozen CRUD endpoints under the `passport.*` route-name
 * prefix (clients, tokens, scopes). They are needed at runtime so the
 * {@see \Radiergummi\OpenApi\Core\Extractors\SecurityExtractor} can resolve
 * `passport.*` route URLs into the OAuth2 security scheme, but they should
 * not surface as application endpoints in the generated document.
 *
 * Tolerates Passport being absent: with no `passport.*` routes registered
 * the filter simply matches nothing.
 *
 * Unlike its sibling filters ({@see SkipNovaRoutes}, {@see SkipTelescopeRoutes},
 * {@see SkipIgnitionRoutes}), Passport's route-name prefix is not
 * user-configurable, so the constructor takes no parameters. The
 * {@see self::fromConfig()} factory is preserved for symmetry with the other
 * filters: it lets the service-provider registration call the same shape on
 * every filter without a special case.
 */
final readonly class SkipPassportRoutes implements RouteFilter
{
    public static function fromConfig(): self
    {
        return new self();
    }

    public function shouldSkip(Route $route): bool
    {
        $name = $route->getName() ?? '';

        return str_starts_with($name, 'passport.');
    }
}
