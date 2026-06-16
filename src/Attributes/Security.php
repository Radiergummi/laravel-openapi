<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Explicitly sets the security requirement, bypassing the middleware-derived default. Empty
 * `$scopes` means "token required, no specific scope". For public endpoints use {@see PublicEndpoint}.
 *
 * `$scheme` must match a key under `openapi.security_schemes`; null falls back to the default.
 *
 * ```php
 * #[OpenApi\Security(['admin', 'projects'])]
 * #[OpenApi\Security(['flights:write'], scheme: 'bearer')]
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Security
{
    /**
     * @param list<non-empty-string> $scopes AND-logic: all listed scopes required.
     * @param null|non-empty-string  $scheme
     */
    public function __construct(
        public array $scopes,
        public ?string $scheme = null,
    ) {}
}
