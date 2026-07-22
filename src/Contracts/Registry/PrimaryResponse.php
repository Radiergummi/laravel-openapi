<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use OpenApi\Annotations as OA;

/**
 * The resolved primary success response, plus how much authority the resolver claims for it.
 * `$statusIsExplicit` marks a status the resolver read from the action itself, which the route
 * convention must not overwrite.
 *
 * @internal Part of the {@see PrimaryResponseResolver} seam; not a committed public contract.
 */
final readonly class PrimaryResponse
{
    private function __construct(
        public OA\Response $response,
        public bool $statusIsExplicit,
    ) {}

    public static function of(OA\Response $response, bool $statusIsExplicit = false): self
    {
        return new self($response, $statusIsExplicit);
    }
}
