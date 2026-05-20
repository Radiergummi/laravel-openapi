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
 * Explicitly sets the security requirement for an operation, bypassing the
 * middleware-based derivation in {@see \Radiergummi\OpenApi\Core\Extractors\SecurityExtractor}.
 *
 * Pass an empty list for "token required but no specific scope" — the same
 * shape as auth-without-scope middleware emits today.
 *
 * The optional `$scheme` names which configured security scheme the requirement
 * targets — match the key under `openapi.security_schemes` or one of the
 * Passport-derived defaults (`oauth2`, `oauth2ClientCredentials`). When omitted,
 * the requirement falls back to the project's default scheme set (Passport's
 * pair if available, otherwise the first config-declared scheme).
 *
 * For truly public endpoints use {@see PublicEndpoint} instead.
 *
 * ```php
 * #[OpenApi\Security(['admin', 'projects'])]
 * public function dangerous() { … }
 *
 * #[OpenApi\Security(['flights:write'], scheme: 'bearer')]
 * public function alsoDangerous() { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Security
{
    /**
     * @param list<string> $scopes Required scopes (AND-logic). Empty list = any valid token.
     * @param null|string  $scheme Optional scheme name; null = project default.
     */
    public function __construct(
        public array $scopes,
        public ?string $scheme = null,
    ) {}
}
