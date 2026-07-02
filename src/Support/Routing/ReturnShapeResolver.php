<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Illuminate\Container\Attributes\Scoped;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Throwable;

use function class_exists;
use function count;
use function strtolower;

/**
 * Derives a normalized {@see ReturnShape} from a controller action's return, merging the native
 * signature with the `@return` PHPDoc. The single Tier-0 answer to "what structural shape does this
 * action return", shared by the response-side plugins so each no longer re-derives container kind,
 * item type, nullability, and unions from raw reflection.
 *
 * Structural classification only: it resolves types, never asks what a class *means*. `@return`
 * generics are unwrapped through {@see ReturnTypeExtractor}; degrades to a null item type (never
 * throws) when a class or type string cannot be resolved.
 *
 * @internal
 */
#[Scoped]
final class ReturnShapeResolver
{
    public function __construct(
        private readonly TypeResolver $typeResolver,
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
        private readonly ReturnTypeExtractor $returnTypeExtractor,
    ) {}

    public static function create(): self
    {
        return new self(
            typeResolver: TypeResolver::create(),
            docBlockParser: DocBlockParser::create(),
            typeNodeResolver: TypeNodeResolver::create(),
            returnTypeExtractor: ReturnTypeExtractor::create(),
        );
    }

    public function describe(ReflectionFunctionAbstract $reflector): ReturnShape
    {
        $native = $reflector->getReturnType();
        $returnNode = $this->returnNode($reflector);

        $nullable = ($native !== null && $native->allowsNull())
            || ($returnNode !== null && $this->typeNodeResolver->isNullable($returnNode));

        if ($native instanceof ReflectionUnionType) {
            return $this->describeUnion($native, $nullable);
        }

        if ($native instanceof ReflectionNamedType && !$native->isBuiltin()) {
            $paginatorKind = PaginatorKind::fromClass($native->getName());

            if ($paginatorKind !== null) {
                return new ReturnShape(
                    container: ReturnContainer::Paginated,
                    itemType: $this->genericItemType($reflector),
                    paginatorKind: $paginatorKind,
                    nullable: $nullable,
                );
            }
        }

        // A documented `@return` decides list-vs-single: an isolated element type (`list<T>`, `T[]`,
        // `array<int, T>`) is a list; anything else (object, map, array shape, single class) is a
        // single value the schema engine renders directly.
        if ($returnNode !== null) {
            $listValue = $this->typeNodeResolver->listValueType($returnNode);

            if ($listValue !== null) {
                return new ReturnShape(
                    container: ReturnContainer::ListOf,
                    itemType: $this->typeNodeResolver->toType($listValue, $reflector),
                    paginatorKind: null,
                    nullable: $nullable,
                );
            }

            return new ReturnShape(
                container: ReturnContainer::Single,
                itemType: $this->typeNodeResolver->toType(
                    $this->typeNodeResolver->unwrapNullable($returnNode),
                    $reflector,
                ),
                paginatorKind: null,
                nullable: $nullable,
            );
        }

        // No `@return`: a bare `array` or an `Enumerable` container is a list of undeclared items.
        if (GenericContainerReturnType::matches($native)) {
            return new ReturnShape(ReturnContainer::ListOf, null, null, $nullable);
        }

        return new ReturnShape(
            container: ReturnContainer::Single,
            itemType: $this->nativeItemType($native),
            paginatorKind: null,
            nullable: $nullable,
        );
    }

    private function returnNode(ReflectionFunctionAbstract $reflector): ?TypeNode
    {
        $comment = $reflector->getDocComment() ?: null;

        if ($comment === null) {
            return null;
        }

        return $this->docBlockParser->parse($comment)->returnType();
    }

    private function describeUnion(ReflectionUnionType $union, bool $nullable): ReturnShape
    {
        $members = [];

        foreach ($union->getTypes() as $member) {
            // The `null` arm carries nullability, tracked separately; it is not a union member.
            if ($member instanceof ReflectionNamedType && strtolower($member->getName()) === 'null') {
                continue;
            }

            try {
                $members[] = $this->typeResolver->resolve($member);
            } catch (Throwable) {
                continue;
            }
        }

        $itemType = match (count($members)) {
            0 => null,
            1 => $members[0],
            default => Type::union(...$members),
        };

        return new ReturnShape(ReturnContainer::Single, $itemType, null, $nullable, $members);
    }

    /** The `@return` generic's value class as an object type, or null when absent/unresolvable. */
    private function genericItemType(ReflectionFunctionAbstract $reflector): ?Type
    {
        $generic = $this->returnTypeExtractor->genericArgument($reflector);

        if ($generic === null || !class_exists($generic)) {
            return null;
        }

        return Type::object($generic);
    }

    private function nativeItemType(?ReflectionType $native): ?Type
    {
        if ($native === null) {
            return null;
        }

        try {
            $type = $this->typeResolver->resolve($native);
        } catch (Throwable) {
            return null;
        }

        return $type instanceof NullableType ? $type->getWrappedType() : $type;
    }
}
