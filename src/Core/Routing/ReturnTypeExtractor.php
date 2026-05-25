<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing;

use Illuminate\Container\Attributes\Scoped;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\PseudoTypes\Generic;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Object_;
use ReflectionFunctionAbstract;
use UnexpectedValueException;

use function array_key_exists;
use function end;
use function ltrim;
use function spl_object_id;

/**
 * Extracts the single generic argument of an action's `@return` PHPDoc tag.
 *
 * PHP native return types cannot carry generics — `function index():
 * LengthAwarePaginator` has no inner type. The inner type lives only in a
 * PHPDoc `@return LengthAwarePaginator<UserResource>`. This reader exposes
 * exactly that one piece of information; it never reads method bodies.
 *
 * Returned names are not verified — callers run `class_exists()` before
 * trusting them.
 */
#[Scoped]
final class ReturnTypeExtractor
{
    /**
     * Memoised `genericArgument()` results for the lifetime of the extractor instance — the
     * extractor is bound as a scoped singleton, so the cache resets between generation runs under
     * Octane. Keyed by `spl_object_id($reflector)`; a stored `null` is a meaningful result
     * (reflector has no `@return` generic) and is distinguished from "uncached" by
     * `array_key_exists`. Saves the heavy `DocBlockFactory::create()` parse + `ContextFactory`
     * walk every time multiple primary-response resolvers consult the same method.
     *
     * @var array<int, ?string>
     */
    private array $genericArgumentCache = [];

    public function __construct(
        private readonly DocBlockFactoryInterface $docBlockFactory,
        private readonly ContextFactory $contextFactory,
    ) {}

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

        try {
            $context = $this->contextFactory->createFromReflector($reflector);
        } catch (UnexpectedValueException) {
            // ContextFactory does not support every Reflector (e.g. closures).
            // Without context, short class names will not resolve — acceptable.
            $context = new Context('');
        }

        $docBlock = $this->docBlockFactory->create($comment, $context);

        foreach ($docBlock->getTagsByName('return') as $tag) {
            if (!$tag instanceof Return_) {
                continue;
            }

            $type = $tag->getType();

            if (!$type instanceof Generic) {
                continue;
            }

            // `Foo<Bar>` yields one type argument, `Foo<Key, Value>` two — the
            // value type is always the last, so a keyed collection resolves to
            // its element type rather than its key type.
            $arguments = $type->getTypes();
            $valueType = end($arguments);

            if ($valueType instanceof Object_ && $valueType->getFqsen() !== null) {
                return $this->genericArgumentCache[$key] = ltrim((string) $valueType->getFqsen(), '\\');
            }
        }

        return $this->genericArgumentCache[$key] = null;
    }

    public static function create(): self
    {
        return new self(
            docBlockFactory: DocBlockFactory::createInstance(),
            contextFactory: new ContextFactory(),
        );
    }
}
