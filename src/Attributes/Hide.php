<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use LogicException;

/**
 * Excludes the annotated route(s) from the generated document. `only` and `except` are
 * mutually exclusive environment filters; with neither, the route is hidden unconditionally.
 *
 *   #[Hide]                            // unconditional
 *   #[Hide(only: ['production'])]      // hide only in production
 *   #[Hide(except: ['local'])]         // hide everywhere except local
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Hide
{
    /**
     * @param null|list<non-empty-string> $only
     * @param null|list<non-empty-string> $except
     *
     * @throws LogicException
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
