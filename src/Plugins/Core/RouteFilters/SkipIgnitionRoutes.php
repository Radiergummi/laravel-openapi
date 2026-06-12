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
 * Excludes Laravel Ignition routes from the generated OpenAPI spec.
 */
#[Scoped]
final readonly class SkipIgnitionRoutes implements RouteFilter
{
    private string $ignitionPath;

    public function __construct(
        #[Config('ignition.housekeeping_endpoint_prefix', '')]
        string $ignitionPath,
    ) {
        $this->ignitionPath = ltrim($ignitionPath, '/');
    }

    #[Override]
    public function shouldSkip(Route $route): bool
    {
        return $this->ignitionPath !== ''
            && str_starts_with($route->uri(), $this->ignitionPath);
    }
}
