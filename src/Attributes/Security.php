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
use Radiergummi\OpenApi\Support\Extraction\SecurityExtractor;

/**
 * Explicitly sets the security requirement, bypassing the middleware-derived default in
 * {@see SecurityExtractor}. Empty `$scopes` means "token required, no specific scope". For truly
 * public endpoints use {@see PublicEndpoint}.
 *
 * `$scheme` must match a key under `openapi.security_schemes` (or a Passport-derived default like
 * `oauth2`, `oauth2ClientCredentials`); when null, falls back to the project's default scheme.
 *
 * ```php
 * #[OpenApi\Security(['admin', 'projects'])]
 * #[OpenApi\Security(['flights:write'], scheme: 'bearer')]
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Security
{
    /**
     * @param list<non-empty-string> $scopes AND-logic — all listed scopes required.
     * @param null|non-empty-string  $scheme
     */
    public function __construct(
        public array $scopes,
        public ?string $scheme = null,
    ) {}
}
