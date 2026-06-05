<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\RouteFilters;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function ltrim;
use function str_starts_with;

/**
 * Excludes Laravel Horizon's dashboard routes from the generated OpenAPI spec.
 *
 * Horizon registers its dashboard under a route group whose URI prefix is `config('horizon.path')`
 * and whose (optional) domain is `config('horizon.domain')` — the exact shape of {@see SkipTelescopeRoutes}.
 *
 * Tolerates Horizon being absent: with no `horizon.path` config present the path is empty and the
 * filter matches nothing.
 */
#[Scoped]
final readonly class SkipHorizonRoutes implements RouteFilter
{
    private string $horizonPath;

    public function __construct(
        #[Config('horizon.path', '')]
        string $horizonPath,
        #[Config('horizon.domain')]
        private ?string $horizonDomain = null,
    ) {
        $this->horizonPath = ltrim($horizonPath, '/');
    }

    public function shouldSkip(Route $route): bool
    {
        return
            ($this->horizonPath !== '' && str_starts_with($route->uri(), $this->horizonPath))
            || ($this->horizonDomain && $route->getDomain() === $this->horizonDomain);
    }
}
