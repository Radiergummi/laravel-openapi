<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Routing;

use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Routing\ResourceTarget;

/**
 * Resolves the resource class an action returns and its cardinality (singular vs. collection).
 */
interface ResourceTargetLocator
{
    /**
     * Returns null when the action does not return a resource.
     */
    public function locate(ActionDescriptor $descriptor): ?ResourceTarget;
}
