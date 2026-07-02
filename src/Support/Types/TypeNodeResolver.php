<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Types;

use Illuminate\Container\Attributes\Scoped;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\GenericType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeContext\TypeContext;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;
use Symfony\Component\TypeInfo\TypeResolver\StringTypeResolver;
use Throwable;

use function array_key_exists;
use function array_key_last;
use function count;
use function in_array;
use function ltrim;
use function strtolower;

/**
 * Resolves phpstan/phpdoc-parser type nodes to FQCNs via symfony/type-info.
 *
 * Returned names are unverified; callers run `class_exists()` before trusting them.
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
     * FQCN of the last generic argument: `Foo<Key, Value>` → `Value`. Unwraps leading `?` / `|null`.
     *
     * @return null|class-string Null when there is no generic, or its value argument is not a plain class.
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
     * Descends through `?` / `|null` to find the inner {@see GenericTypeNode}, or null if absent.
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
     * Converts a phpstan/phpdoc-parser {@see TypeNode} into a symfony/type-info {@see Type}, the
     * canonical representation the schema engine consumes. The single phpstan → symfony bridge.
     *
     * Resolution runs through the reflection-derived {@see TypeContext} so relative/imported class
     * names (`list<User>`, `array{author: Author}`) resolve; a bare `StringTypeResolver` throws on
     * them. Returns null when the type string is unresolvable, e.g. it names a class the resolver
     * cannot verify, so the caller degrades to its own fallback rather than aborting the run.
     */
    public function toType(TypeNode $node, Reflector $context): ?Type
    {
        $typeContext = $this->contextFor($context);

        try {
            return $this->normalizeSameNamespaceClass(
                $this->stringResolver->resolve((string) $node, $typeContext),
            );
        } catch (Throwable) {
            // A derived context can carry an unrelated class scope (Pest closures) or a name the
            // resolver cannot verify. Retry context-free before giving up.
            if ($typeContext === null) {
                return null;
            }

            try {
                return $this->normalizeSameNamespaceClass(
                    $this->stringResolver->resolve((string) $node),
                );
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * Works around a symfony/type-info quirk: a bare same-namespace class name (no `use` import) is
     * wrapped in a {@see CollectionType} even though it is a plain object. The quirk always wraps an
     * {@see ObjectType}, so only that case is unwrapped — a genuine array/collection wraps a
     * {@see GenericType} (`array<K, V>`, `list<T>`, `Collection<T>`) or a bare {@see BuiltinType}
     * (bare `array` / `iterable`), and both must survive as CollectionType for the list/map mapping.
     */
    private function normalizeSameNamespaceClass(Type $type): Type
    {
        if ($type instanceof NullableType) {
            return Type::nullable($this->normalizeSameNamespaceClass($type->getWrappedType()));
        }

        if ($type instanceof CollectionType && $type->getWrappedType() instanceof ObjectType) {
            return $type->getWrappedType();
        }

        return $type;
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
            // Failed in the derived context (e.g., a closure inheriting an unrelated class scope,
            // as Pest does). Retry with null context to treat the name as a global class name.
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

        // symfony/type-info wraps same-namespace identifiers (no `use` import) in CollectionType; unwrap one level.
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
            // A malformed import-type alias or unreadable file must not abort the run;
            // degrade to context-free resolution (bare names won't resolve without context).
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
     * @param class-string|string $className
     *
     * @return class-string
     */
    private function asSpeculativeClassName(string $className): string
    {
        /** @var class-string */
        return ltrim($className, '\\');
    }

    /**
     * Element type of a JSON-list type node: `list<T>`, `non-empty-list<T>`, `array<T>`,
     * `non-empty-array<T>`, `array<int, T>`, or `T[]`, after unwrapping a leading nullable.
     * Returns null for map-shaped generics (`array<string, T>`) and all other shapes.
     */
    public function listValueType(TypeNode $node): ?TypeNode
    {
        $inner = $this->unwrapNullable($node);

        if ($inner instanceof ArrayTypeNode) {
            return $inner->type;
        }

        if (!$inner instanceof GenericTypeNode) {
            return null;
        }

        $name = strtolower($inner->type->name);

        // list<T> / non-empty-list<T>: the single argument is the value type.
        if (in_array($name, ['list', 'non-empty-list'], strict: true)
            && count($inner->genericTypes) === 1
        ) {
            return $inner->genericTypes[0];
        }

        if (in_array($name, ['array', 'non-empty-array'], strict: true)) {
            if (count($inner->genericTypes) === 1) {
                return $inner->genericTypes[0];
            }

            // Two-argument array<K, V>: an integer key is a list; a string key is a map.
            if (count($inner->genericTypes) === 2) {
                $key = $inner->genericTypes[0];

                if ($key instanceof IdentifierTypeNode && in_array(
                    strtolower($key->name),
                    ['int', 'integer'],
                    strict: true,
                )) {
                    return $inner->genericTypes[1];
                }
            }
        }

        return null;
    }

    /**
     * Strips one nullable wrapper: `?T` → `T`, `T|null` → `T` (single non-null member only).
     * Returns the node unchanged for all other shapes.
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
     * True for `?T` ({@see NullableTypeNode}) or a union containing a bare `null` (`T|null`).
     */
    public function isNullable(TypeNode $node): bool
    {
        if ($node instanceof NullableTypeNode) {
            return true;
        }

        if (($node instanceof UnionTypeNode) && array_any(
            $node->types,
            fn(TypeNode $member): bool
                    => $member instanceof IdentifierTypeNode
                    && strtolower($member->name) === 'null',
        )) {
            return true;
        }

        return false;
    }

    /**
     * FQCN of a single-class type node, unwrapping `?` / `|null` first.
     *
     * @return null|class-string Null for scalars, generics, arrays, or unresolvable identifiers.
     */
    public function resolveClassName(TypeNode $node, Reflector $context): ?string
    {
        $inner = $this->unwrapNullable($node);

        // If unwrapNullable returned the same node it is either a plain IdentifierTypeNode or a
        // multi-member union; the instanceof check below handles both.
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
     * FQCNs of every class in a (possibly union) `@throws` node, in source order.
     *
     * @return list<class-string>
     */
    public function throwsClasses(TypeNode $node, Reflector $context): array
    {
        /** @var list<class-string> $classes */
        $classes = [];

        foreach ($this->flatten($node) as $identifier) {
            // Fall back to the written name: a documented-but-unresolvable exception must still
            // surface for error-response mapping and the throws.unmapped lint rule. Unlike
            // genericValueClass, where an unresolvable name would produce a broken $ref target.
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
}
