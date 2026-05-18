<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Registry;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

/**
 * Resolves the primary success response (`200 OK`) for a single controller action.
 *
 * Returns null when this resolver cannot determine a response for the given action,
 * allowing the next resolver in the chain to be consulted (first non-null wins).
 *
 * Implementations are responsible for graceful degradation — exceptions should be
 * caught internally and null returned, so one bad endpoint does not abort a full
 * generation run.
 */
interface PrimaryResponseResolver
{
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response;
}
