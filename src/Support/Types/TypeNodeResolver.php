<?php

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
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeContext\TypeContext;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;
use Symfony\Component\TypeInfo\TypeResolver\StringTypeResolver;
use Throwable;

use function array_key_exists;
use function array_key_last;
use function ltrim;
use function strtolower;

/**
 * Resolves phpstan/phpdoc-parser type nodes to FQCNs, using symfony/type-info to resolve short
 * names against the declaring file's namespace and `use` imports.
 *
 * Returned names are not verified: Callers run `class_exists()` before trusting them. Bound as a
 * scoped singleton; the context cache resets between runs.
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
        private readonly StringTypeResolver $stringResolver = new StringTypeResolver(),
        private readonly TypeContextFactory $contextFactory = new TypeContextFactory(
            new StringTypeResolver(),
        ),
    ) {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * FQCN (no leading backslash) of the *value* argument of a generic type: `Foo<Key, Value>`
     * resolves to `Value` (the last argument). A leading `?` or a `|null` union is unwrapped first,
     * so `?Foo<Bar>` and `Foo<Bar>|null` resolve too.
     *
     * @return null|class-string Null when there is no generic, or its value argument is not a
     *                           plain class.
     */
    public function genericValueClass(TypeNode $node, Reflector $context): ?string
    {
        $generic = $this->unwrapGeneric($node);

        if ($generic === null || $generic->genericTypes === []) {
            return null;
        }

        $value = $generic->genericTypes[array_key_last($generic->genericTypes)];

        return $value instanceof IdentifierTypeNode
            ? $this->resolveClass($value, $context)
            : null;
    }

    /**
     * Locates the {@see GenericTypeNode} within a type node, descending through a nullable
     * wrapper (`?Foo<Bar>`) or a union (`Foo<Bar>|null`).
     * Returns null when there is none.
     */
    private function unwrapGeneric(TypeNode $node): ?GenericTypeNode
    {
        if ($node instanceof GenericTypeNode) {
            return $node;
        }

        if ($node instanceof NullableTypeNode) {
            return $this->unwrapGeneric($node->type);
        }

        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $inner) {
                $generic = $this->unwrapGeneric($inner);

                if ($generic !== null) {
                    return $generic;
                }
            }
        }

        return null;
    }

    /**
     * Descends through a single nullable wrapper to reach the inner type node:
     * - `?T` → `T`
     * - `T|null` → `T` (only when there is exactly one non-null member)
     * - Anything else → returned unchanged
     *
     * Shared by {@see resolveClassName()} and {@see EloquentModelToSchema} so the null-unwrap logic
     * has a single canonical implementation.
     */
    public function unwrapNullable(TypeNode $node): TypeNode
    {
        if ($node instanceof NullableTypeNode) {
            return $node->type;
        }

        if ($node instanceof UnionTypeNode) {
            $nonNull = null;

            foreach ($node->types as $member) {
                if ($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null') {
                    continue;
                }

                // Multi-class union, not a simple nullable; return unchanged.
                if ($nonNull !== null) {
                    return $node;
                }

                $nonNull = $member;
            }

            return $nonNull ?? $node;
        }

        return $node;
    }

    /**
     * Returns true when the type node represents a nullable type: a {@see NullableTypeNode}
     * (`?T`) or a {@see UnionTypeNode} containing a bare `null` identifier (`T|null`).
     */
    public function isNullable(TypeNode $node): bool
    {
        if ($node instanceof NullableTypeNode) {
            return true;
        }

        if (($node instanceof UnionTypeNode) && array_any(
            $node->types,
            fn(TypeNode $member)
                    => $member instanceof IdentifierTypeNode
                    && strtolower($member->name) === 'null',
        )) {
            return true;
        }

        return false;
    }

    /**
     * Resolves the FQCN (no leading backslash) of a type node that denotes a single class,
     * unwrapping a leading `?` or a `T|null` union first.
     *
     * @return null|class-string Null for scalar keywords, generics, arrays, or unresolvable
     *                           identifiers.
     */
    public function resolveClassName(TypeNode $node, Reflector $context): ?string
    {
        $inner = $this->unwrapNullable($node);

        // If unwrapNullable returned the same node, it is either:
        //   (a) a plain IdentifierTypeNode needing no unwrap, handled by the identifier check
        //       below, or
        //   (b) a multi-member union that cannot resolve to a single class, the instanceof check
        //       returns null.
        if ($inner !== $node) {
            return $inner instanceof IdentifierTypeNode
                ? $this->resolveClass($inner, $context)
                : null;
        }

        return $node instanceof IdentifierTypeNode
            ? $this->resolveClass($node, $context)
            : null;
    }

    /**
     * @return null|class-string
     */
    private function resolveClass(IdentifierTypeNode $node, Reflector $context): ?string
    {
        $typeContext = $this->contextFor($context);

        try {
            $type = $this->stringResolver->resolve($node->name, $typeContext);
        } catch (Throwable) {
            // The identifier could not be resolved in the derived context (e.g., a bare class name
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

        if ($type instanceof ObjectType) {
            return $this->asSpeculativeClassName($type->getClassName());
        }

        // symfony/type-info wraps same-namespace class identifiers (no `use` import needed) in a
        // CollectionType whose inner wrapped type is the ObjectType — unwrap one level.
        if ($type instanceof CollectionType) {
            $wrappedType = $type->getWrappedType();

            if ($wrappedType instanceof ObjectType) {
                return $this->asSpeculativeClassName($wrappedType->getClassName());
            }
        }

        return null;
    }

    private function contextFor(Reflector $context): ?TypeContext
    {
        $key = $this->cacheKey($context);

        if ($key !== null && array_key_exists($key, $this->contextCache)) {
            return $this->contextCache[$key];
        }

        try {
            $resolved = $this->contextFactory->createFromReflection($context);
        } catch (Throwable) {
            // Building the context tokenizes the declaring file and resolves every
            // `@psalm-import-type`/`@phpstan-import-type` alias on the class — annotations this
            // resolver never consumes. A malformed alias or an unreadable file must not abort the
            // whole generation/lint run, so degrade to a context-free resolution (bare names
            // simply won't resolve).
            $resolved = null;
        }

        if ($key !== null) {
            $this->contextCache[$key] = $resolved;
        }

        return $resolved;
    }

    /**
     * @return null|non-empty-string
     */
    private function cacheKey(Reflector $context): ?string
    {
        return match (true) {
            $context instanceof ReflectionClass => $context->getName(),
            $context instanceof ReflectionMethod => sprintf(
                '%s::%s',
                $context->getDeclaringClass()->getName(),
                $context->getName(),
            ),
            default => null,
        };
    }

    /**
     * FQCNs (no leading backslash) of every class in a (possibly union) `@throws` node, in
     * source order.
     *
     * @return list<class-string> List of Exception FQCNs
     */
    public function throwsClasses(TypeNode $node, Reflector $context): array
    {
        /** @var list<class-string> $classes */
        $classes = [];

        foreach ($this->flatten($node) as $identifier) {
            // Fall back to the written name when the class cannot be resolved to an existing class.
            // `@throws` feeds error-response mapping and the throws.unmapped lint rule (both
            // `class_exists()`-guard), so a documented-but-unresolvable exception name must still
            // surface rather than vanish silently. Return-type generics intentionally do NOT do
            // this (see genericValueClass): emitting an unresolvable class as a $ref target would
            // produce a broken document, so omission is the safer choice there.
            /** @var class-string $className */
            $className = $this->resolveClass($identifier, $context)
                ?? $this->asSpeculativeClassName($identifier->name);

            $classes[] = $className;
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

    /**
     * @param class-string|string $className
     *
     * @return class-string
     */
    private function asSpeculativeClassName(string $className): string
    {
        /** @var class-string */
        return ltrim($className, '\\');
    }
}
