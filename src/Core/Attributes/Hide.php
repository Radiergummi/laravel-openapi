<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Excludes the annotated route(s) from the generated OpenAPI document.
 *
 * Applied to a controller class, every route declared on that class is
 * excluded. Applied to a single method, only that method's routes are
 * excluded. Useful for internal endpoints that should not show up in the
 * public API reference yet.
 *
 * Pass `environments` to scope the hiding to specific application
 * environments (matched against {@see \Illuminate\Foundation\Application::environment()}).
 * `#[Hide]` with no argument hides unconditionally; `#[Hide(environments: ['staging', 'production'])]`
 * hides only when running in one of those environments.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Hide
{
    /**
     * @param null|list<string> $environments Environments to hide in. `null` (the default) hides unconditionally.
     */
    public function __construct(
        public ?array $environments = null,
    ) {}
}
