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
 * Excludes Laravel Nova routes from the generated OpenAPI spec.
 */
final readonly class SkipNovaRoutes implements RouteFilter
{
    public function __construct(
        private string $novaPath,
        private ?string $novaDomain,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            novaPath: ltrim((string) config('nova.path', ''), '/'),
            novaDomain: config('nova.domain'),
        );
    }

    public function shouldSkip(Route $route): bool
    {
        return
            ($this->novaPath !== '' && str_starts_with($route->uri(), $this->novaPath))
            || str_starts_with($route->uri(), 'nova-')
            || ($this->novaDomain && $route->getDomain() === $this->novaDomain);
    }
}
