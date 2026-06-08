<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use ReflectionClass;
use ReflectionMethod;

use function str_contains;
use function str_starts_with;

/**
 * How an authored swagger-php annotation was written — as `#[OA\*]` PHP attributes or as an `@OA`
 * PHPDoc block. The migration removal fixers need this to pick the right edit: attributes are
 * removed via the AST, docblock blocks via line-based comment surgery.
 *
 * @internal
 */
enum AuthoredSchemaShape: string
{
    /** swagger-php's PHP-attribute namespace (`#[OA\Schema]` etc.), distinct from `@OA` docblocks. */
    public const string ATTRIBUTE_NAMESPACE = 'OpenApi\\Attributes\\';

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
        foreach ($reflector->getAttributes() as $attribute) {
            if (str_starts_with($attribute->getName(), self::ATTRIBUTE_NAMESPACE)) {
                return self::Attribute;
            }
        }

        $docComment = $reflector->getDocComment();

        if ($docComment !== false && str_contains($docComment, '@OA\\')) {
            return self::Docblock;
        }

        return null;
    }
}
