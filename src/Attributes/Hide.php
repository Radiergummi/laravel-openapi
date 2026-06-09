<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Excludes the annotated route(s) from the generated document. `only` and `except` are
 * mutually exclusive environment filters; with neither, the route is hidden unconditionally.
 *
 *   #[Hide]                            // unconditional
 *   #[Hide(only: ['production'])]      // hide only in production
 *   #[Hide(except: ['local'])]         // hide everywhere except local
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Hide extends VisibilityAttribute {}
