<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * Derives conventional operation defaults — a success status code and/or a default summary — from
 * a route's Tier-0 signals (action method name, HTTP verb, controller name), without parsing any
 * method body.
 *
 * Returns null when the resolver recognises no convention for the action, allowing the next
 * resolver in the chain to be consulted (first non-null wins). The defaults sit at the lowest
 * precedence: an explicit `#[Response]`/`#[Summary]`/`#[Operation]` attribute or a DocComment
 * always wins over them.
 *
 * Implementations are responsible for graceful degradation — exceptions should be caught
 * internally and null returned, so one bad endpoint does not abort a full generation run.
 */
interface OperationConventionResolver
{
    public function resolve(ActionDescriptor $descriptor): ?OperationConvention;
}
