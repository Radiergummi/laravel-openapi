<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Visibility;

use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;

use function in_array;

/**
 * Decides whether a route should appear in the generated OpenAPI document.
 * Caller passes the union of method-level and class-level Hide/Expose
 * attribute instances; the resolver does not perform reflection.
 */
final readonly class VisibilityResolver
{
    public function __construct(
        private VisibilityMode $defaultMode = VisibilityMode::Public,
    ) {}

    /**
     * @param list<Hide>   $hides   All Hide attributes that apply to the route (method + class).
     * @param list<Expose> $exposes All Expose attributes that apply to the route (method + class).
     */
    public function isVisible(array $hides, array $exposes, string $environment): bool
    {
        foreach ($hides as $hide) {
            if ($this->scopeMatches($hide->only, $hide->except, $environment)) {
                return false;
            }
        }

        foreach ($exposes as $expose) {
            if ($this->scopeMatches($expose->only, $expose->except, $environment)) {
                return true;
            }
        }

        return $this->defaultMode === VisibilityMode::Public;
    }

    /**
     * @param null|list<string> $only
     * @param null|list<string> $except
     */
    private function scopeMatches(?array $only, ?array $except, string $environment): bool
    {
        if ($only === null && $except === null) {
            return true;
        }

        if ($only !== null) {
            return in_array($environment, $only, true);
        }

        // $except !== null by elimination
        return !in_array($environment, $except ?? [], true);
    }
}
