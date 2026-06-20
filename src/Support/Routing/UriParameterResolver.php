<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use BackedEnum;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\CreatesRegularExpressionRouteConstraints;
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
use function class_uses_recursive;
use function explode;
use function in_array;
use function is_subclass_of;
use function preg_match;
use function str_contains;

/**
 * @internal
 */
#[Scoped]
final readonly class UriParameterResolver
{
    /**
     * Regex Laravel's `Route::whereUuid()` writes into `$route->wheres`.
     *
     * @see CreatesRegularExpressionRouteConstraints::whereUuid()
     */
    private const string LARAVEL_UUID_REGEX
        = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

    /**
     * Regex Laravel's `Route::whereNumber()` writes into `$route->wheres`.
     *
     * @see CreatesRegularExpressionRouteConstraints::whereNumber
     */
    private const string LARAVEL_NUMBER_REGEX = '[0-9]+';

    public function __construct(
        private TypeResolver $typeResolver,
    ) {}

    /**
     * @param null|string $whereConstraint Raw regex from `$route->wheres[]`, or null when no
     *                                     constraint is registered on the route.
     * @param null|string $bindingField    Custom binding field from a `{param:field}` route segment
     *                                     (e.g., `slug`), or null when the parameter has no custom key.
     *
     * @throws UnsupportedException When symfony/type-info cannot resolve the reflection type.
     */
    public function resolve(
        ReflectionParameter $parameter,
        ?string $whereConstraint,
        ?string $bindingField = null,
    ): UriParameterDescriptor {
        $type = $this->resolveType($parameter);

        // NullableType extends UnionType, unwrap to get the concrete inner type.
        $innerType = $type instanceof NullableType
            ? $type->getWrappedType()
            : $type;

        return $this->buildDescriptor(
            name: $parameter->getName(),
            type: $type,
            optional: $type->isNullable(),
            whereConstraint: $whereConstraint,
            modelBinding: $this->resolveModelBinding($innerType, $bindingField),
            enumCases: $this->resolveEnumCases($innerType),
        );
    }

    /**
     * Resolves a URI placeholder that has no corresponding controller signature parameter
     * (invokable controllers, `Request`-only actions, the parent of a scoped/nested binding).
     *
     * The type defaults to `string`, which is always correct: a path segment is read from the URL
     * as a string. Model-class recovery is intentionally not attempted, since reflection cannot see
     * a bound model without a typed signature parameter. `where*` constraints still enrich the
     * schema (uuid/integer/enum/pattern).
     *
     * @param null|string $whereConstraint Raw regex from `$route->wheres[]`, or null when none.
     * @param null|string $bindingField    Custom binding field from a `{param:field}` segment, or
     *                                     null. Reserved for parity with {@see resolve()}; an
     *                                     unsignatured bind stays a bare string.
     * @param bool        $optional        True when the placeholder is `{name?}`.
     */
    public function resolveUnsignatured(
        string $name,
        ?string $whereConstraint,
        ?string $bindingField = null,
        bool $optional = false,
    ): UriParameterDescriptor {
        return $this->buildDescriptor(
            name: $name,
            type: Type::builtin(TypeIdentifier::STRING),
            optional: $optional,
            whereConstraint: $whereConstraint,
            modelBinding: null,
            enumCases: null,
        );
    }

    /**
     * @param null|list<string> $enumCases Case values from a backed-enum signature type, or null.
     */
    private function buildDescriptor(
        string $name,
        Type $type,
        bool $optional,
        ?string $whereConstraint,
        ?RouteModelBinding $modelBinding,
        ?array $enumCases,
    ): UriParameterDescriptor {
        [$resolvedConstraint, $whereKind] = $this->resolveConstraint($whereConstraint);

        // A `whereIn()` constraint enumerates its literal alternatives; surface them as the schema's
        // enum when the type did not already provide cases (a backed enum wins).
        if ($whereKind === WhereKind::In && $enumCases === null && $resolvedConstraint !== null) {
            $enumCases = explode('|', $resolvedConstraint);
        }

        return new UriParameterDescriptor(
            name: $name,
            type: $type,
            optional: $optional,
            whereConstraint: $resolvedConstraint,
            whereKind: $whereKind,
            enumCases: $enumCases,
            modelBinding: $modelBinding,
        );
    }

    /**
     * Falls back to `string` when the parameter is untyped, as URI parameters are always read from
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
     * Classifies a route constraint regex into a {@see WhereKind} by matching known Laravel patterns.
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
     * Maps a raw constraint regex to a {@see WhereKind}; falls back to {@see WhereKind::Custom}.
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
     * Detects `Route::whereIn()` output: `|`-joined plain literals with no metacharacters.
     * Any metacharacter in an alternative falls through to {@see WhereKind::Custom}.
     */
    private function isLiteralAlternation(string $regex): bool
    {
        if (!str_contains($regex, '|')) {
            return false;
        }

        return array_all(
            explode('|', $regex),
            fn(string $alternative): bool
                => (
                    $alternative !== ''
                && preg_match('/[\[\](){}\\\\+*?.^$]/', $alternative) !== 1
                ),
        );
    }

    /**
     * Resolves a route-model-bound parameter into a {@see RouteModelBinding}.
     * Key type/format is resolved only when binding by the model's own primary key.
     * `BackedEnumType` is checked first because it extends `ObjectType` in symfony/type-info.
     */
    private function resolveModelBinding(Type $innerType, ?string $bindingField): ?RouteModelBinding
    {
        if ($innerType instanceof BackedEnumType) {
            return null;
        }

        if (!$innerType instanceof ObjectType) {
            return null;
        }

        $className = $innerType->getClassName();

        if (!is_subclass_of($className, UrlRoutable::class)) {
            return null;
        }

        // Bypass the constructor: bound models may declare required constructor
        // arguments, and the route key name never depends on constructor state.
        try {
            $instance = new ReflectionClass($className)->newInstanceWithoutConstructor();
            $key = $bindingField ?? $instance->getRouteKeyName();

            // Type the key only when the route binds by the model's own primary key: a custom
            // field (or an overridden route key) describes a different column we cannot type here.
            // Non-Eloquent UrlRoutables expose no key metadata, so they stay untyped too.
            [$type, $format] = $instance instanceof Model && $key === $instance->getKeyName()
                ? $this->resolveKeyType($instance, $className)
                : [null, null];
        } catch (Throwable) {
            return null;
        }

        return new RouteModelBinding($className, $key, $type, $format);
    }

    /**
     * Returns the JSON-Schema type and format for a model's primary key.
     * Detects `HasUuids`/`HasUlids` by trait rather than `getKeyType()`, which relies on
     * constructor state that is bypassed here.
     *
     * @param class-string $className
     *
     * @return array{string, ?string}
     */
    private function resolveKeyType(Model $instance, string $className): array
    {
        $traits = class_uses_recursive($className);

        if (in_array(HasUuids::class, $traits, true)) {
            return ['string', 'uuid'];
        }

        // ULID keys are strings, but there is no standard OpenAPI format for them.
        if (in_array(HasUlids::class, $traits, true)) {
            return ['string', null];
        }

        return [$instance->getKeyType() === 'int' ? 'integer' : 'string', null];
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
