<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use ReflectionNamedType;
use ReflectionType;

use function is_a;

/**
 * Predicate identifying a *loose response wrapper* return type: a framework response class
 * (`Illuminate\Http\JsonResponse` / `Illuminate\Http\Response` and their Symfony parents) that is
 * too generic to carry a resolvable payload type in its signature.
 *
 * An action declaring such a return type while returning a resource by convention
 * (`return UserResource::make($user)`, coerced to a response by the framework) carries its only type
 * evidence in the body, so callers consult their return-expression body scan. Named against
 * {@see GenericContainerReturnType}, which does the same for collection containers.
 *
 * @internal
 */
final class LooseResponseReturnType
{
    private const string SYMFONY_RESPONSE = 'Symfony\\Component\\HttpFoundation\\Response';

    public static function matches(?ReflectionType $type): bool
    {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        // Illuminate\Http\JsonResponse and Illuminate\Http\Response both extend the Symfony base, so
        // one is_a check covers the whole loose-wrapper set.
        return is_a($type->getName(), self::SYMFONY_RESPONSE, allow_string: true);
    }
}
