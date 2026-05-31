<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Types;

use Illuminate\Container\Attributes\Scoped;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeContext\TypeContext;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;
use Symfony\Component\TypeInfo\TypeResolver\StringTypeResolver;
use Throwable;

use function array_key_exists;
use function array_key_last;
use function ltrim;

/**
 * Resolves phpstan/phpdoc-parser type nodes to FQCNs, using symfony/type-info to
 * resolve short names against the declaring file's namespace and `use` imports.
 *
 * Returned names are not verified — callers run `class_exists()` before trusting
 * them. Bound as a scoped singleton; the context cache resets between runs.
 *
 * @internal
 */
#[Scoped]
final class TypeNodeResolver
{
    /**
     * @var array<string, ?TypeContext>
     */
    private array $contextCache = [];

    public function __construct(
        private readonly StringTypeResolver $stringResolver,
        private readonly TypeContextFactory $contextFactory,
    ) {}

    /**
     * FQCN (no leading backslash) of the *value* argument of a generic type —
     * `Foo<Key, Value>` resolves to `Value` (the last argument). Returns null
     * when the node is not a generic, or its value argument is not a plain class.
     */
    public function genericValueClass(TypeNode $node, Reflector $context): ?string
    {
        if (!$node instanceof GenericTypeNode || $node->genericTypes === []) {
            return null;
        }

        $value = $node->genericTypes[array_key_last($node->genericTypes)];

        return $value instanceof IdentifierTypeNode
            ? $this->resolveClass($value, $context)
            : null;
    }

    /**
     * FQCNs (no leading backslash) of every class in a (possibly union) `@throws`
     * node, in source order.
     *
     * @return list<string>
     */
    public function throwsClasses(TypeNode $node, Reflector $context): array
    {
        $classes = [];

        foreach ($this->flatten($node) as $identifier) {
            $fqcn = $this->resolveClass($identifier, $context);

            if ($fqcn !== null) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    /**
     * @return iterable<IdentifierTypeNode>
     */
    private function flatten(TypeNode $node): iterable
    {
        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $inner) {
                yield from $this->flatten($inner);
            }

            return;
        }

        if ($node instanceof NullableTypeNode) {
            yield from $this->flatten($node->type);

            return;
        }

        if ($node instanceof IdentifierTypeNode) {
            yield $node;
        }
    }

    private function resolveClass(IdentifierTypeNode $node, Reflector $context): ?string
    {
        $typeContext = $this->contextFor($context);

        try {
            $type = $this->stringResolver->resolve($node->name, $typeContext);
        } catch (Throwable) {
            // The identifier could not be resolved in the derived context (e.g. a bare class name
            // inside a closure whose scope was inherited from an unrelated class, as Pest does with
            // test closures). Retry with a null context so the resolver treats the identifier as a
            // global class name — the only meaningful fallback for context-free annotations.
            if ($typeContext === null) {
                return null;
            }

            try {
                $type = $this->stringResolver->resolve($node->name);
            } catch (Throwable) {
                return null;
            }
        }

        return $type instanceof ObjectType
            ? ltrim($type->getClassName(), '\\')
            : null;
    }

    private function contextFor(Reflector $context): ?TypeContext
    {
        $key = $this->cacheKey($context);

        if ($key !== null && array_key_exists($key, $this->contextCache)) {
            return $this->contextCache[$key];
        }

        $resolved = $this->contextFactory->createFromReflection($context);

        if ($key !== null) {
            $this->contextCache[$key] = $resolved;
        }

        return $resolved;
    }

    private function cacheKey(Reflector $context): ?string
    {
        return match (true) {
            $context instanceof ReflectionClass => $context->getName(),
            $context instanceof ReflectionMethod => $context->getDeclaringClass()->getName() . '::' . $context->getName(),
            default => null,
        };
    }

    public static function create(): self
    {
        $stringResolver = new StringTypeResolver();

        return new self(
            stringResolver: $stringResolver,
            contextFactory: new TypeContextFactory($stringResolver),
        );
    }
}
