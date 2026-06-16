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
 * Excludes Horizon dashboard routes from the generated spec.
 *
 * Matches on `config('horizon.path')` (URI prefix) and `config('horizon.domain')`.
 * When Horizon is absent the path is empty and the filter matches nothing.
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

    #[Override]
    public function shouldSkip(Route $route): bool
    {
        return
            ($this->horizonPath !== '' && str_starts_with($route->uri(), $this->horizonPath))
            || ($this->horizonDomain && $route->getDomain() === $this->horizonDomain);
    }
}
