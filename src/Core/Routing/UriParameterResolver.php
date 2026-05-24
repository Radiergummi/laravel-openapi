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
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Routing\UrlRoutable;
use ReflectionClass;
use ReflectionParameter;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Throwable;

use function array_map;
use function explode;
use function is_subclass_of;
use function preg_match;
use function str_contains;

#[Scoped]
final readonly class UriParameterResolver
{
    /**
     * Regex Laravel's `Route::whereUuid()` writes into `$route->wheres`.
     *
     * @see \Illuminate\Routing\CreatesRegularExpressionRouteConstraints::whereUuid()
     */
    private const string LARAVEL_UUID_REGEX
        = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

    /**
     * Regex Laravel's `Route::whereNumber()` writes into `$route->wheres`.
     *
     * @see \Illuminate\Routing\CreatesRegularExpressionRouteConstraints::whereNumber()
     */
    private const string LARAVEL_NUMBER_REGEX = '[0-9]+';

    public function __construct(
        private TypeResolver $typeResolver,
    ) {}

    /**
     * @param null|string $whereConstraint Raw regex from `$route->wheres[]`, or null when no
     *                                     constraint is registered on the route.
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

        [$resolvedConstraint, $whereKind] = $this->resolveConstraint($whereConstraint);

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
     * Classifies the route's native constraint regex (from `$route->wheres[]`) into a
     * {@see WhereKind}. Laravel's `Route::where*()` helpers — and Spatie's `#[Where*]`
     * attributes, which delegate to them — each write a known regex string, so the kind
     * can be derived from the regex alone without any attribute reflection.
     *
     * @return array{null|string, null|WhereKind}
     */
    private function resolveConstraint(?string $routeWhereConstraint): array
    {
        if ($routeWhereConstraint === null) {
            return [null, null];
        }

        return [$routeWhereConstraint, $this->classifyConstraint($routeWhereConstraint)];
    }

    /**
     * Maps a raw constraint regex to a {@see WhereKind}. Falls back to {@see WhereKind::Custom}
     * for anything that is not an exact match for a known Laravel pattern.
     */
    private function classifyConstraint(string $regex): WhereKind
    {
        if ($regex === self::LARAVEL_UUID_REGEX) {
            return WhereKind::Uuid;
        }

        if ($regex === self::LARAVEL_NUMBER_REGEX) {
            return WhereKind::Number;
        }

        if ($this->isLiteralAlternation($regex)) {
            return WhereKind::In;
        }

        return WhereKind::Custom;
    }

    /**
     * Detects the shape `Route::whereIn()` produces: alternatives joined by `|` where every
     * alternative is a plain literal. Conservative — any regex metacharacter in an
     * alternative disqualifies the whole string, so genuine custom regexes fall through to
     * {@see WhereKind::Custom}.
     */
    private function isLiteralAlternation(string $regex): bool
    {
        if (!str_contains($regex, '|')) {
            return false;
        }

        foreach (explode('|', $regex) as $alternative) {
            if ($alternative === '' || preg_match('/[\[\](){}\\\\+*?.^$]/', $alternative) === 1) {
                return false;
            }
        }

        return true;
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
