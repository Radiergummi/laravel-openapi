<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Includes the annotated route(s) when running in hidden-by-default mode
 * (`openapi.visibility.default === 'hidden'`). No-op in public-by-default mode (flagged by the
 * `visibility.attribute-no-op` rule). Environment scoping mirrors {@see Hide}. On conflict with
 * `#[Hide]`, hide wins; the `visibility.hide-expose-conflict` rule reports it.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Expose extends VisibilityAttribute {}
