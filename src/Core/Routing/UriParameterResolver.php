<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing;

use BackedEnum;
use Illuminate\Contracts\Routing\UrlRoutable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;
use Spatie\RouteAttributes\Attributes\Where;
use Spatie\RouteAttributes\Attributes\WhereIn;
use Spatie\RouteAttributes\Attributes\WhereNumber;
use Spatie\RouteAttributes\Attributes\WhereUuid;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Throwable;

use function array_map;
use function is_subclass_of;

final readonly class UriParameterResolver
{
    public function __construct(
        private TypeResolver $typeResolver,
    ) {}

    /**
     * @param null|string $whereConstraint Raw regex from `$route->wheres[]`, or null when no
     *                                     constraint is registered on the route (even if a `Where*`
     *                                     attribute exists — some are not pushed
     *                                     into `$route->wheres`).
     *
     * @throws UnsupportedException When symfony/type-info cannot resolve the reflection type.
     */
    public function resolve(
        ReflectionParameter $parameter,
        ?string $whereConstraint,
    ): UriParameterDescriptor {
        $type = $this->resolveType($parameter);
        $optional = $type->isNullable();

        // NullableType extends UnionType, unwrap to get the concrete inner type.
        $innerType = $type instanceof NullableType
            ? $type->getWrappedType()
            : $type;

        $whereAttribute = $this->findWhereAttributeFor($parameter);

        [$resolvedConstraint, $whereKind] = $this->resolveConstraint(
            $whereAttribute,
            $whereConstraint,
        );

        [$modelClass, $routeKeyName] = $this->resolveModelBinding($innerType);
        $enumCases = $this->resolveEnumCases($innerType);

        return new UriParameterDescriptor(
            name: $parameter->getName(),
            type: $type,
            optional: $optional,
            whereConstraint: $resolvedConstraint,
            whereKind: $whereKind,
            modelClass: $modelClass,
            routeKeyName: $routeKeyName,
            enumCases: $enumCases,
        );
    }

    /**
     * Falls back to `string` when the parameter is untyped — URI parameters are always read from
     * the URL as strings.
     *
     * @throws UnsupportedException When the reflection type cannot be resolved.
     */
    private function resolveType(ReflectionParameter $parameter): Type
    {
        $reflectionType = $parameter->getType();

        if ($reflectionType === null) {
            return Type::builtin(TypeIdentifier::STRING);
        }

        return $this->typeResolver->resolve($reflectionType);
    }

    /**
     * The param-name filter is critical: class-level `#[WhereUuid('project')]` must not match a
     * sibling parameter like `$rfiProcess`.
     */
    private function findWhereAttributeFor(ReflectionParameter $parameter): ?ReflectionAttribute
    {
        $name = $parameter->getName();

        $candidates = [
            ...$parameter->getAttributes(),
            ...$parameter->getDeclaringFunction()->getAttributes(),
            ...$parameter->getDeclaringClass()?->getAttributes() ?? [],
        ];

        $found = null;

        foreach ($candidates as $attr) {
            if (!is_subclass_of($attr->getName(), Where::class)) {
                continue;
            }

            $instance = $attr->newInstance();

            if ($instance->param === $name) {
                $found = $attr;
            }
        }

        return $found;
    }

    /**
     * @return array{null|string, null|WhereKind}
     */
    private function resolveConstraint(
        ?ReflectionAttribute $whereAttribute,
        ?string $routeWhereConstraint,
    ): array {
        if ($whereAttribute !== null) {
            $instance = $whereAttribute->newInstance();

            return match ($whereAttribute->getName()) {
                WhereUuid::class => [$instance->constraint, WhereKind::Uuid],
                WhereNumber::class => [$instance->constraint, WhereKind::Number],
                WhereIn::class => [$instance->constraint, WhereKind::In],
                default => [$instance->constraint, WhereKind::Custom],
            };
        }

        if ($routeWhereConstraint !== null) {
            return [$routeWhereConstraint, WhereKind::Custom];
        }

        return [null, null];
    }

    /**
     * `BackedEnumType` extends `ObjectType` in symfony/type-info — check it first to avoid treating
     * an enum as a model binding.
     *
     * @return array{class-string, string}|array{null, null}
     */
    private function resolveModelBinding(Type $innerType): array
    {
        if ($innerType instanceof BackedEnumType) {
            return [null, null];
        }

        if (!$innerType instanceof ObjectType) {
            return [null, null];
        }

        $className = $innerType->getClassName();

        if (!is_subclass_of($className, UrlRoutable::class)) {
            return [null, null];
        }

        // Bypass the constructor: bound models may declare required constructor
        // arguments, and the route key name never depends on constructor state.
        try {
            $instance = new ReflectionClass($className)->newInstanceWithoutConstructor();
            $routeKeyName = $instance->getRouteKeyName();
        } catch (Throwable) {
            return [null, null];
        }

        return [$className, $routeKeyName];
    }

    /**
     * @return null|list<string>
     */
    private function resolveEnumCases(Type $innerType): ?array
    {
        if (!$innerType instanceof BackedEnumType) {
            return null;
        }

        /** @var class-string<BackedEnum> $className */
        $className = $innerType->getClassName();

        return array_map(
            static fn(BackedEnum $case): string => (string) $case->value,
            $className::cases(),
        );
    }
}
