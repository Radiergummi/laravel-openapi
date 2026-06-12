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
 * Extracts the single generic argument of an action's `@return` PHPDoc tag.
 *
 * PHP native return types cannot carry generics — `function index(): LengthAwarePaginator` has no
 * inner type. The inner type lives only in a PHPDoc `return LengthAwarePaginator<UserResource>`.
 * This reader exposes exactly that one piece of information; it never reads method bodies.
 *
 * Returned names are not verified — callers run `class_exists()` before trusting them.
 *
 * @internal
 */
#[Scoped]
final class ReturnTypeExtractor
{
    /**
     * Memoised `genericArgument()` results for the lifetime of the extractor instance — the
     * extractor is bound as a scoped singleton, so the cache resets between generation runs under
     * Octane. Keyed by `spl_object_id($reflector)`; a stored `null` is a meaningful result
     * (reflector has no `@return` generic) and is distinguished from "uncached" by
     * `array_key_exists`.
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

    /**
     * Returns the FQCN (without a leading backslash) of the generic argument of
     * the at-return tag, or null when there is no docblock, no at-return tag,
     * or no generic argument.
     */
    public function genericArgument(ReflectionFunctionAbstract $reflector): ?string
    {
        $key = spl_object_id($reflector);

        if (array_key_exists($key, $this->genericArgumentCache)) {
            return $this->genericArgumentCache[$key];
        }

        $comment = $reflector->getDocComment();

        if ($comment === false || $comment === '') {
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
