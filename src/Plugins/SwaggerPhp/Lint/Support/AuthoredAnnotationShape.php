<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

use function str_contains;
use function str_starts_with;

/**
 * How an authored `@OA` annotation was written — as `#[OA\*]` PHP attributes or as an `@OA` PHPDoc
 * block. The migration fixers need this to pick the right edit: attributes via the AST, docblock
 * blocks via line-based comment surgery.
 *
 * @internal
 */
enum AuthoredAnnotationShape: string
{
    /** swagger-php's PHP-attribute namespace (`#[OA\Schema]` etc.), distinct from `@OA` docblocks. */
    public const string ATTRIBUTE_NAMESPACE = 'OpenApi\\Attributes\\';

    /** Finding-context key a rule stores the detected shape under, for its fixer to read back. */
    public const string FINDING_CONTEXT_KEY = 'oaAnnotationShape';

    case Attribute = 'attribute';

    case Docblock = 'docblock';

    /**
     * The shape of the authored `@OA` annotation on a class or method, or null when neither an
     * `#[OA\*]` attribute nor an `@OA` docblock is present (so callers never propose an edit they
     * cannot locate).
     *
     * @param ReflectionClass<object>|ReflectionMethod $reflector
     */
    public static function detect(ReflectionClass|ReflectionMethod $reflector): ?self
    {
        if (array_any(
            $reflector->getAttributes(),
            fn(ReflectionAttribute $attribute): bool => str_starts_with(
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
