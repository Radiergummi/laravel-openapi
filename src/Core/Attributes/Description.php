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
 * Sets the long-form description of the surrounding symbol.
 *
 * Two uses:
 *
 * - **Operations** (controller method, controller class, or closure route) — overrides the value
 *   derived from the docblock or {@see Operation::$description}. Standalone alternative to
 *   `#[Operation(description: '…')]`. Precedence: method `#[Description]` → method
 *   `#[Operation(description)]` → method docblock → class `#[Description]` → class
 *   `#[Operation(description)]`. The method docblock always outranks class-level attributes.
 *   Class-level placement is intended for `__invoke` (single-action) controllers, where it
 *   outranks the class docblock.
 * - **Schemas** — placed on a Spatie `Data` class or an Eloquent `JsonResource` class, it sets
 *   the component schema's `description`.
 *
 * ```php
 * #[OpenApi\Description('Returns paginated results matching the given query.')]
 * public function search(): JsonResponse { … }
 *
 * #[OpenApi\Description('A confirmed flight booking with passenger details.')]
 * final class FlightBookingData extends Data { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Description
{
    public function __construct(public string $value) {}
}
