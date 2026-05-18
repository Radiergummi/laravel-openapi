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
 * Excludes Laravel Telescope routes from the generated OpenAPI spec.
 */
final readonly class SkipTelescopeRoutes implements RouteFilter
{
    public function __construct(
        private string $telescopePath,
        private ?string $telescopeDomain,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            telescopePath: ltrim((string) config('telescope.path', ''), '/'),
            telescopeDomain: config('telescope.domain'),
        );
    }

    public function shouldSkip(Route $route): bool
    {
        return
            ($this->telescopePath !== '' && str_starts_with($route->uri(), $this->telescopePath))
            || ($this->telescopeDomain && $route->getDomain() === $this->telescopeDomain);
    }
}
