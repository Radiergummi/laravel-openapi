<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Attributes;

/**
 * Marker for authoring attributes that identify the source of an operation's primary response
 * (e.g. `#[ResponseResource]`, `#[FractalResponse]`).
 *
 * Plugin attributes consumed by a primary-response resolver should implement this interface so
 * scanning resolvers step aside and let the attribute's own resolver claim the response.
 */
interface PrimaryResponseAuthoringAttribute {}
