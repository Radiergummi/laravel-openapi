<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionException;

/**
 * Resolves query parameters for a single controller action.
 *
 * Implementations examine the action descriptor (controller method, attributes, etc.)
 * and return the `OA\Parameter` objects that should be added to the operation.
 * Return an empty array when this resolver has nothing to contribute for the given action.
 */
interface QueryParameterResolver
{
    /**
     * @return list<OA\Parameter>
     *
     * @throws ReflectionException
     */
    public function resolveQueryParameters(ActionDescriptor $descriptor): array;
}
