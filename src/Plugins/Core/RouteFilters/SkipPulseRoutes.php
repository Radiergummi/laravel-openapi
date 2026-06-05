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
 * Excludes Laravel Pulse's dashboard route from the generated OpenAPI spec.
 *
 * Pulse registers its dashboard under a route group whose URI prefix is `config('pulse.path')`
 * and whose (optional) domain is `config('pulse.domain')` — the exact shape of {@see SkipTelescopeRoutes}.
 *
 * Tolerates Pulse being absent: with no `pulse.path` config present the path is empty and the
 * filter matches nothing.
 */
#[Scoped]
final readonly class SkipPulseRoutes implements RouteFilter
{
    private string $pulsePath;

    public function __construct(
        #[Config('pulse.path', '')]
        string $pulsePath,
        #[Config('pulse.domain')]
        private ?string $pulseDomain = null,
    ) {
        $this->pulsePath = ltrim($pulsePath, '/');
    }

    public function shouldSkip(Route $route): bool
    {
        return
            ($this->pulsePath !== '' && str_starts_with($route->uri(), $this->pulsePath))
            || ($this->pulseDomain && $route->getDomain() === $this->pulseDomain);
    }
}
