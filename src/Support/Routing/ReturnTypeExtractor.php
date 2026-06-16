<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionFunctionAbstract;

use function array_key_exists;
use function spl_object_id;

/**
 * Extracts the generic argument from an action's `@return` PHPDoc tag (e.g. the `UserResource`
 * in `LengthAwarePaginator<UserResource>`). Returned names are unverified; callers check
 * `class_exists()`.
 *
 * @internal
 */
#[Scoped]
final class ReturnTypeExtractor
{
    /**
     * Keyed by `spl_object_id($reflector)`. A stored `null` (distinguished from absent by
     * `array_key_exists`) means no generic was found.
     *
     * @var array<int, ?string>
     */
    private array $genericArgumentCache = [];

    public function __construct(
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
    ) {}

    public static function create(): self
    {
        return new self(
            docBlockParser: DocBlockParser::create(),
            typeNodeResolver: TypeNodeResolver::create(),
        );
    }

    /** Returns the FQCN of the generic argument of the `@return` tag, or null if absent. */
    public function genericArgument(ReflectionFunctionAbstract $reflector): ?string
    {
        $key = spl_object_id($reflector);

        if (array_key_exists($key, $this->genericArgumentCache)) {
            return $this->genericArgumentCache[$key];
        }

        $comment = $reflector->getDocComment() ?: null;

        if ($comment === null) {
            return $this->genericArgumentCache[$key] = null;
        }

        $returnType = $this->docBlockParser->parse($comment)->returnType();

        if ($returnType === null) {
            return $this->genericArgumentCache[$key] = null;
        }

        return $this->genericArgumentCache[$key] = $this->typeNodeResolver->genericValueClass(
            $returnType,
            $reflector,
        );
    }
}
