<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\RouteFilters;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Override;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function str_starts_with;

/**
 * Excludes Passport's internal routes (`passport.*`) from the generated spec.
 *
 * Those routes are needed at runtime for OAuth2 URL resolution but must not surface as
 * application endpoints. Safe when Passport is absent (matches nothing). The prefix is
 * not user-configurable, so this filter takes no constructor arguments.
 */
#[Scoped]
final readonly class SkipPassportRoutes implements RouteFilter
{
    #[Override]
    public function shouldSkip(Route $route): bool
    {
        $name = $route->getName() ?? '';

        return str_starts_with($name, 'passport.');
    }
}
