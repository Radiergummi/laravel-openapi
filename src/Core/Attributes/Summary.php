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
 * Sets the human-readable summary of the surrounding symbol.
 *
 * Two uses:
 *
 * - **Operations** (controller method, controller class, or closure route) — overrides the value
 *   derived from the docblock or {@see Operation::$summary}. Standalone alternative to
 *   `#[Operation(summary: '…')]` for the common case where summary is the only field overridden.
 *   Precedence: method `#[Summary]` → method `#[Operation(summary)]` → method docblock → class
 *   `#[Summary]` → class `#[Operation(summary)]`. The method docblock always outranks class-level
 *   attributes — anything written on the method itself wins. Class-level placement is intended
 *   for `__invoke` (single-action) controllers, where it outranks the class docblock.
 * - **Schemas** — placed on a Spatie `Data` class or an Eloquent `JsonResource` class, it sets
 *   the component schema's `title`.
 *
 * ```php
 * #[OpenApi\Summary('Search products')]
 * public function search(): JsonResponse { … }
 *
 * #[OpenApi\Summary('Flight booking')]
 * final class FlightBookingData extends Data { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Summary
{
    public function __construct(public string $value) {}
}
