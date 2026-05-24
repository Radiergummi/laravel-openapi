<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;
use LogicException;

/**
 * Includes the annotated route(s) in the generated OpenAPI document when the
 * package is operating in hidden-by-default mode
 * (`config('openapi.visibility.default') === 'hidden'`). In public-by-default
 * mode the attribute is a no-op and is flagged by the
 * `visibility.attribute-no-op` lint rule.
 *
 * Applied to a controller class, every route declared on that class is
 * exposed. Applied to a single method, only that method's routes are.
 *
 * Environment scoping mirrors {@see Hide}: `only` exposes *only* when the
 * application environment is in the list; `except` exposes *everywhere
 * except* the listed environments. Passing both throws {@see LogicException}.
 *
 * Conflict resolution: when both `#[Hide]` and `#[Expose]` apply to the same
 * route in the current environment, `#[Hide]` wins. The
 * `visibility.hide-expose-conflict` lint rule reports the conflict.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Expose
{
    /**
     * @param null|list<string> $only   Expose *only* when `app()->environment()` is one of these.
     * @param null|list<string> $except Expose *except* when `app()->environment()` is one of these.
     *
     * @throws LogicException
     */
    public function __construct(
        public ?array $only = null,
        public ?array $except = null,
    ) {
        if ($only !== null && $except !== null) {
            throw new LogicException(
                '#[Expose] cannot use both `only` and `except` — they are mutually exclusive.',
            );
        }
    }
}
