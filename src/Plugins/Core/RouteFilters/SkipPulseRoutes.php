<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\RouteFilters;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Override;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function ltrim;
use function str_starts_with;

/**
 * Excludes Laravel Pulse's dashboard routes from the spec.
 * Matches by URI prefix (`pulse.path`) and optional domain (`pulse.domain`).
 * Tolerates Pulse being absent: an empty path matches nothing.
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

    #[Override]
    public function shouldSkip(Route $route): bool
    {
        return
            ($this->pulsePath !== '' && str_starts_with($route->uri(), $this->pulsePath))
            || ($this->pulseDomain && $route->getDomain() === $this->pulseDomain);
    }
}
