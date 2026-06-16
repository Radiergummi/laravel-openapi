<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionException;

/**
 * Resolves query parameters for a single controller action.
 * Return an empty array when the resolver has nothing to contribute.
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
