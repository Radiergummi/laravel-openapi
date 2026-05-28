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
 * Excludes Laravel Telescope routes from the generated OpenAPI spec.
 */
#[Scoped]
final readonly class SkipTelescopeRoutes implements RouteFilter
{
    private string $telescopePath;

    public function __construct(
        #[Config('telescope.path', '')]
        string $telescopePath,
        #[Config('telescope.domain')]
        private ?string $telescopeDomain = null,
    ) {
        $this->telescopePath = ltrim($telescopePath, '/');
    }

    public function shouldSkip(Route $route): bool
    {
        return
            ($this->telescopePath !== '' && str_starts_with($route->uri(), $this->telescopePath))
            || ($this->telescopeDomain && $route->getDomain() === $this->telescopeDomain);
    }
}
