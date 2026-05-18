<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing;

use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Types\Collection;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Object_;
use ReflectionFunctionAbstract;
use UnexpectedValueException;

use function ltrim;

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
final class ReturnTypeExtractor
{
    public function __construct(
        private readonly DocBlockFactoryInterface $docBlockFactory,
        private readonly ContextFactory $contextFactory,
    ) {}

    /**
     * Returns the FQCN (without a leading backslash) of the generic argument of
     * the `@return` tag, or null when there is no docblock, no `@return` tag,
     * or no generic argument.
     */
    public function genericArgument(ReflectionFunctionAbstract $reflector): ?string
    {
        $comment = $reflector->getDocComment();

        if ($comment === false || $comment === '') {
            return null;
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

            if (!$type instanceof Collection) {
                continue;
            }

            $valueType = $type->getValueType();

            if ($valueType instanceof Object_ && $valueType->getFqsen() !== null) {
                return ltrim((string) $valueType->getFqsen(), '\\');
            }
        }

        return null;
    }

    public static function create(): self
    {
        return new self(
            docBlockFactory: DocBlockFactory::createInstance(),
            contextFactory: new ContextFactory(),
        );
    }
}
