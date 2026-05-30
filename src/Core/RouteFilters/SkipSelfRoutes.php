<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\RouteFilters;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function str_starts_with;

/**
 * Excludes the library's own spec/playground routes from the generated OpenAPI spec.
 *
 * Matches on the `openapi.` Laravel-route-name prefix set by
 * {@see \Radiergummi\OpenApi\Support\Spec\SpecDefinition::specRouteNameFor()} and
 * {@see \Radiergummi\OpenApi\Support\Spec\SpecDefinition::playgroundRouteNameFor()}.
 * Default-on so a stock install does not document its own spec endpoint; remove
 * the entry from `config('openapi.filters')` to opt in.
 */
#[Scoped]
final readonly class SkipSelfRoutes implements RouteFilter
{
    public function shouldSkip(Route $route): bool
    {
        $name = $route->getName() ?? '';

        return str_starts_with($name, 'openapi.');
    }
}
