<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\RouteFilters;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Override;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function str_starts_with;

/**
 * Excludes the library's own spec/playground routes (prefixed `openapi.`) from the generated spec.
 *
 * Remove from `config('openapi.filters')` to include them.
 */
#[Scoped]
final readonly class SkipSelfRoutes implements RouteFilter
{
    #[Override]
    public function shouldSkip(Route $route): bool
    {
        $name = $route->getName() ?? '';

        return str_starts_with($name, 'openapi.');
    }
}
