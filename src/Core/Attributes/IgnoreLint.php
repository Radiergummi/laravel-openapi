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

/**
 * Suppresses a single OpenAPI lint rule for the annotated symbol.
 *
 * Place on a controller class, a controller action, or a Data-class property. Class scope silences
 * the rule for the whole file; method scope for that action's findings; property scope for
 * findings derived from that property (e.g. the `field.*` rules). Stack the attribute to suppress
 * several rules.
 *
 * Every directive should carry a `reason` — the `meta.no-suppression-reason` rule flags those that
 * don't.
 */
#[Attribute(
    Attribute::TARGET_CLASS
    | Attribute::TARGET_CLASS_CONSTANT
    | Attribute::TARGET_METHOD
    | Attribute::TARGET_PROPERTY
    | Attribute::IS_REPEATABLE,
)]
final readonly class IgnoreLint
{
    public function __construct(
        public string $rule,
        public ?string $reason = null,
    ) {}
}
