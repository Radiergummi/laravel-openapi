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
use LogicException;

/**
 * Excludes the annotated route(s) from the generated OpenAPI document.
 *
 * Applied to a controller class, every route declared on that class is
 * excluded. Applied to a single method, only that method's routes are
 * excluded. Useful for internal endpoints that should not show up in the
 * public API reference yet.
 *
 * Environment scoping: pass `only` to hide *only* when the application
 * environment is in the list, or `except` to hide *everywhere except* the
 * listed environments. The two arguments are mutually exclusive — passing
 * both throws {@see LogicException}. With neither, the route is hidden
 * unconditionally.
 *
 * Examples:
 *   #[Hide]                            // hide unconditionally
 *   #[Hide(only: ['production'])]      // hide only in production
 *   #[Hide(except: ['local'])]         // hide everywhere except local
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Hide
{
    /**
     * @param null|list<string> $only   Hide *only* when `app()->environment()` is one of these.
     * @param null|list<string> $except Hide *except* when `app()->environment()` is one of these.
     */
    public function __construct(
        public ?array $only = null,
        public ?array $except = null,
    ) {
        if ($only !== null && $except !== null) {
            throw new LogicException(
                '#[Hide] cannot use both `only` and `except` — they are mutually exclusive.',
            );
        }
    }
}
