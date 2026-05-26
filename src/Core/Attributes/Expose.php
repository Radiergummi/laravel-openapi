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
 * Includes the annotated route(s) when running in hidden-by-default mode
 * (`openapi.visibility.default === 'hidden'`). No-op in public-by-default mode (flagged by the
 * `visibility.attribute-no-op` rule). Environment scoping mirrors {@see Hide}. On conflict with
 * `#[Hide]`, hide wins; the `visibility.hide-expose-conflict` rule reports it.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Expose
{
    /**
     * @param null|list<string> $only
     * @param null|list<string> $except
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
