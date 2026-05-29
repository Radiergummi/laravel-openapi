<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Illuminate\Container\Attributes\Scoped;
use phpDocumentor\Reflection\DocBlock\Tags\Throws as ThrowsTag;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Object_;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use UnexpectedValueException;

use function ltrim;
use function method_exists;

/**
 * Resolves `@throws` annotations to FQCNs via phpDocumentor.
 *
 * Returned names are not verified — callers run `class_exists()` before trusting them.
 */
#[Scoped]
final class ThrowsExtractor
{
    /**
     * @var array<string, Context>
     */
    private array $contextCache = [];

    public function __construct(
        private readonly DocBlockFactoryInterface $docBlockFactory,
        private readonly ContextFactory $contextFactory,
    ) {}

    /**
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    public function extract(Reflector $reflector): array
    {
        if (!method_exists($reflector, 'getDocComment')) {
            return [];
        }

        $comment = $reflector->getDocComment();

        if ($comment === false || $comment === '') {
            return [];
        }

        $context = $this->contextFor($reflector);
        $docBlock = $this->docBlockFactory->create($comment, $context);

        $fqcns = [];

        foreach ($docBlock->getTagsByName('throws') as $tag) {
            if (!$tag instanceof ThrowsTag) {
                continue;
            }

            $type = $tag->getType();

            if ($type === null) {
                continue;
            }

            foreach ($this->flattenTypes($type) as $name) {
                $fqcns[] = $name;
            }
        }

        return $fqcns;
    }

    private function contextFor(Reflector $reflector): Context
    {
        // For trait-composed methods PHP's reflection reports the using class as the declaring
        // class, which would make phpDocumentor read the using class's `use` statements instead
        // of the trait's. Substitute the trait's ReflectionClass so the trait's file context
        // (its namespace and imports) is what resolves bare `@throws` names.
        $source = $this->definingTraitFor($reflector) ?? $reflector;

        $fileName = $this->fileNameFor($source);

        if ($fileName !== null && isset($this->contextCache[$fileName])) {
            return $this->contextCache[$fileName];
        }

        try {
            $context = $this->contextFactory->createFromReflector($source);
        } catch (UnexpectedValueException) {
            // phpDocumentor's ContextFactory does not support all Reflector types (e.g.,
            // ReflectionFunction for closures). Fall back to a context-free default so @throws
            // FQCNs in closure docblocks are still resolved: without namespace context, bare class
            // names won't resolve, but that is acceptable.
            return new Context('');
        }

        if ($fileName !== null) {
            $this->contextCache[$fileName] = $context;
        }

        return $context;
    }

    /**
     * Returns the trait that lexically defines the method, when the reflector is a method
     * composed via `use TraitName`. Returns `null` for direct methods, inherited methods,
     * or non-method reflectors.
     *
     * @return null|ReflectionClass<object>
     */
    private function definingTraitFor(Reflector $reflector): ?ReflectionClass
    {
        if (!$reflector instanceof ReflectionMethod) {
            return null;
        }

        return $this->findDefiningTrait($reflector->getDeclaringClass(), $reflector->getName());
    }

    /**
     * Walks the trait hierarchy depth-first and returns the deepest trait that declares
     * a method with the given name. Returns `null` if no trait declares it.
     *
     * @param ReflectionClass<object> $class
     *
     * @return null|ReflectionClass<object>
     */
    private function findDefiningTrait(ReflectionClass $class, string $methodName): ?ReflectionClass
    {
        foreach ($class->getTraits() as $trait) {
            $deeper = $this->findDefiningTrait($trait, $methodName);

            if ($deeper !== null) {
                return $deeper;
            }

            if ($trait->hasMethod($methodName)) {
                return $trait;
            }
        }

        return null;
    }

    private function fileNameFor(Reflector $reflector): ?string
    {
        if (!method_exists($reflector, 'getFileName')) {
            return null;
        }

        return $reflector->getFileName() ?: null;
    }

    public static function create(): self
    {
        return new self(
            docBlockFactory: DocBlockFactory::createInstance(),
            contextFactory: new ContextFactory(),
        );
    }

    /**
     * @return iterable<string>
     */
    private function flattenTypes(Type $type): iterable
    {
        if ($type instanceof Compound) {
            foreach ($type as $inner) {
                yield from $this->flattenTypes($inner);
            }

            return;
        }

        if ($type instanceof Object_) {
            $fqsen = $type->getFqsen();

            if ($fqsen !== null) {
                yield ltrim((string) $fqsen, '\\');
            }
        }
    }
}
