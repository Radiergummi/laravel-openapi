<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Attributes;

/**
 * Marker for authoring attributes that identify the source of an operation's primary response
 * (e.g. `#[ResponseResource]`, `#[FractalResponse]`).
 *
 * Scanning resolvers check for this marker via
 * {@see \Radiergummi\OpenApi\Routing\ActionDescriptor::declaresAttributeImplementing()} and step
 * aside so the attribute's own resolver can claim the response. Plugin attributes consumed by a
 * primary-response resolver should implement this interface.
 */
interface PrimaryResponseAuthoringAttribute {}
