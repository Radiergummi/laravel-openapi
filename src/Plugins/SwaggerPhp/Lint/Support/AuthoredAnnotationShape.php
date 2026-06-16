<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

use function str_contains;
use function str_starts_with;

/**
 * Whether a `@OA` annotation was written as a `#[OA\*]` PHP attribute or an `@OA` PHPDoc block.
 * Migration fixers use this to pick the right edit strategy.
 *
 * @internal
 */
enum AuthoredAnnotationShape: string
{
    /** swagger-php PHP-attribute namespace (`#[OA\Schema]` etc.). */
    public const string ATTRIBUTE_NAMESPACE = 'OpenApi\\Attributes\\';

    /** Finding-context key used to pass the detected shape from a rule to its fixer. */
    public const string FINDING_CONTEXT_KEY = 'oaAnnotationShape';

    case Attribute = 'attribute';

    case Docblock = 'docblock';

    /**
     * Detects the shape on a class or method, or returns null when neither is present.
     *
     * @param ReflectionClass<object>|ReflectionMethod $reflector
     */
    public static function detect(ReflectionClass|ReflectionMethod $reflector): ?self
    {
        if (array_any(
            $reflector->getAttributes(),
            fn(ReflectionAttribute $attribute): bool
                => str_starts_with(
                    $attribute->getName(),
                    self::ATTRIBUTE_NAMESPACE,
                ),
        )) {
            return self::Attribute;
        }

        $docComment = $reflector->getDocComment();

        if ($docComment !== false && str_contains($docComment, '@OA\\')) {
            return self::Docblock;
        }

        return null;
    }
}
