<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\RouteFilters;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function ltrim;
use function str_starts_with;

/**
 * Excludes Laravel Nova routes from the generated OpenAPI spec.
 */
#[Scoped]
final readonly class SkipNovaRoutes implements RouteFilter
{
    private string $novaPath;

    public function __construct(
        #[Config('nova.path', '')]
        string $novaPath,
        #[Config('nova.domain')]
        private ?string $novaDomain = null,
    ) {
        $this->novaPath = ltrim($novaPath, '/');
    }

    public function shouldSkip(Route $route): bool
    {
        // Beyond the configured `nova.path`, Nova also registers internal tool and
        // asset routes under a fixed `nova-` URI prefix (e.g. `nova-api/*`,
        // `nova-vendor/*`). Those are independent of `nova.path`, so the literal
        // prefix is matched explicitly to exclude them as well.
        return
            ($this->novaPath !== '' && str_starts_with($route->uri(), $this->novaPath))
            || str_starts_with($route->uri(), 'nova-')
            || ($this->novaDomain && $route->getDomain() === $this->novaDomain);
    }
}
