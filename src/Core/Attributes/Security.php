<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Explicitly sets the OAuth2 scopes required for an operation, bypassing the
 * middleware-based derivation in {@see \Radiergummi\OpenApi\Core\Extractors\SecurityExtractor}.
 *
 * Pass an empty list for "token required but no specific scope" — the same
 * shape as auth-without-scope middleware emits today.
 *
 * For truly public endpoints use {@see PublicEndpoint} instead.
 *
 * ```php
 * #[OpenApi\Security(['admin', 'projects'])]
 * public function dangerous() { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Security
{
    /**
     * @param list<string> $scopes Required OAuth2 scopes (AND-logic). Empty list = any valid token.
     */
    public function __construct(public array $scopes) {}
}
