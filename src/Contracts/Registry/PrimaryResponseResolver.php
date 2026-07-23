<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * Resolves the primary success response for a single controller action.
 * Returns null to pass to the next resolver (first non-null wins). Catch exceptions internally
 * and return null so one bad endpoint does not abort a full generation run.
 * A result carries the response together with whether its status was read from the action, and is
 * built through {@see PrimaryResponse::of()}.
 */
interface PrimaryResponseResolver
{
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?PrimaryResponse;
}
