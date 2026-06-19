<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

use function str_contains;
use function str_starts_with;

/**
 * Detects whether a Spatie Data member carries a member-level `#[OA\*]` attribute or an `@OA`
 * property docblock the per-member migration fixers can isolate. Shared by the per-member migration
 * rules: a member with no such annotation cannot be addressed at single-member granularity.
 *
 * @internal
 */
trait DetectsPropertyShape
{
    /**
     * The shape of the member-level OA annotation on a class member, or null when the member carries
     * none the fixer can isolate (e.g. the annotation lives on the class-level schema instead).
     *
     * @param class-string $class
     *
     * @throws ReflectionException
     */
    private function propertyShape(string $class, string $propertyName): ?AuthoredAnnotationShape
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->hasProperty($propertyName)) {
            $shape = $this->reflectorShape(new ReflectionProperty($class, $propertyName));

            if ($shape !== null) {
                return $shape;
            }
        }

        // A promoted constructor parameter carries its attributes on the parameter, not a property.
        $constructor = $reflection->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $propertyName) {
                foreach ($parameter->getAttributes() as $attribute) {
                    if (str_starts_with($attribute->getName(), AuthoredAnnotationShape::ATTRIBUTE_NAMESPACE)) {
                        return AuthoredAnnotationShape::Attribute;
                    }
                }
            }
        }

        return null;
    }

    private function reflectorShape(ReflectionProperty $property): ?AuthoredAnnotationShape
    {
        foreach ($property->getAttributes() as $attribute) {
            if (str_starts_with($attribute->getName(), AuthoredAnnotationShape::ATTRIBUTE_NAMESPACE)) {
                return AuthoredAnnotationShape::Attribute;
            }
        }

        $docComment = $property->getDocComment();

        return $docComment !== false && str_contains($docComment, '@OA\\')
            ? AuthoredAnnotationShape::Docblock
            : null;
    }
}
