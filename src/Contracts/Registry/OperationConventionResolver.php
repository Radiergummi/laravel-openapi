<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * Derives conventional operation defaults (status code, summary) from route signals without
 * parsing method bodies. First non-null in the chain wins; explicit attributes and DocComments
 * take precedence. Implementations must catch exceptions and return null so one bad endpoint
 * cannot abort a run.
 *
 * @internal
 */
interface OperationConventionResolver
{
    public function resolve(ActionDescriptor $descriptor): ?OperationConvention;
}
