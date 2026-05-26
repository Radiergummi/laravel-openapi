<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes\Support;

/**
 * Sentinel used as the default value for nullable-mixed scoped-field-attribute parameters
 * (`example`, `enum`) so that the descriptor() consumer can distinguish "author did not pass this
 * argument" from "author explicitly passed null" — the latter is a deliberate suppression of any
 * value the description directive would otherwise provide.
 */
enum FieldDefault
{
    case Unset;
}
