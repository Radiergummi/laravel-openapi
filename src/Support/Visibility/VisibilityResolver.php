<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Visibility;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\Attributes\Hide;

/**
 * Decides whether a route should appear in the generated OpenAPI document.
 *
 * Caller passes the union of method-level and class-level Hide/Expose attribute instances; the
 * resolver does not perform reflection.
 *
 * @internal
 */
#[Scoped]
final readonly class VisibilityResolver
{
    public VisibilityMode $defaultMode;

    public function __construct(
        #[Config('openapi.visibility.default')]
        VisibilityMode|string|null $defaultMode = VisibilityMode::Public,
    ) {
        $this->defaultMode = VisibilityMode::fromConfig($defaultMode);
    }

    /**
     * @param list<Hide>   $hides   All Hide attributes that apply to the route (method + class).
     * @param list<Expose> $exposes All Expose attributes that apply to the route (method + class).
     */
    public function isVisible(array $hides, array $exposes, string $environment): bool
    {
        if (array_any($hides, fn(Hide $hide): bool => $hide->appliesIn($environment))) {
            return false;
        }

        if (array_any($exposes, fn(Expose $expose): bool => $expose->appliesIn($environment))) {
            return true;
        }

        return $this->defaultMode === VisibilityMode::Public;
    }
}
