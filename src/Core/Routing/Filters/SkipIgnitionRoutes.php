<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing\Filters;

use Illuminate\Routing\Route;

use function config;
use function ltrim;
use function str_starts_with;

/**
 * Excludes Laravel Ignition routes from the generated OpenAPI spec.
 */
final readonly class SkipIgnitionRoutes implements RouteFilter
{
    public function __construct(private string $ignitionPath) {}

    public static function fromConfig(): self
    {
        return new self(
            ignitionPath: ltrim((string) config('ignition.housekeeping_endpoint_prefix', ''), '/'),
        );
    }

    public function shouldSkip(Route $route): bool
    {
        return $this->ignitionPath !== ''
            && str_starts_with($route->uri(), $this->ignitionPath);
    }
}
