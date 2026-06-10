<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Attributes;

/**
 * Marker for authoring attributes that name the source of an operation's primary (success)
 * response — `#[ResponseResource]`, `#[FractalResponse]`, and the like.
 *
 * An explicit authoring attribute always wins over body-scan inference (epic #5), but the
 * resolver that *consumes* the attribute may sit later in the primary-response chain than a
 * scanning resolver. Scanning resolvers therefore check
 * {@see \Radiergummi\OpenApi\Routing\ActionDescriptor::declaresAttributeImplementing()} for this
 * marker and step aside, so the attribute's own resolver gets to claim the response. Plugin
 * attributes that a primary-response resolver consumes should implement this interface.
 */
interface PrimaryResponseAuthoringAttribute {}
