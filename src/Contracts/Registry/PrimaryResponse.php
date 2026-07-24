<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use OpenApi\Annotations as OA;

/**
 * The resolved primary success response, plus how much authority the resolver claims for it.
 * `$statusIsExplicit` marks a status the resolver read from the action itself, which the route
 * convention must not overwrite. `$suppressed` marks a resolver claiming that the action has no
 * success response at all (a `never` return), distinct from returning `null` to abstain: the
 * synthetic `200 OK` fallback must not fire, so `$response` is `null` in that case.
 *
 * Part of the {@see PrimaryResponseResolver} seam; not a committed public contract while the
 * package is pre-1.0. Unlike the sibling {@see OperationConvention}, construction goes through
 * named constructors rather than a public one, so further cases can be added without reshaping it.
 */
final readonly class PrimaryResponse
{
    private function __construct(
        public ?OA\Response $response,
        public bool $statusIsExplicit,
        public bool $suppressed,
    ) {}

    public static function of(OA\Response $response, bool $statusIsExplicit = false): self
    {
        return new self($response, $statusIsExplicit, suppressed: false);
    }

    /**
     * A resolver claiming the action has no success response (a `never` return): the synthetic
     * `200 OK` fallback is suppressed rather than a status being resolved.
     */
    public static function suppressed(): self
    {
        return new self(null, statusIsExplicit: false, suppressed: true);
    }
}
