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

/**
 * Marks an operation as fully public — no security requirement is emitted.
 *
 * Use this when middleware-based derivation misrepresents the endpoint, e.g. a route that has
 * `auth:api` in the stack purely for context but is effectively reachable without a token. Emits
 * `security: []` in the spec.
 *
 * Mutually exclusive with {@see Security}; if both are present, this wins.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class PublicEndpoint {}
