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
     *                                     (e.g. `slug`), or null when the parameter has no custom key.
     *
     * @throws UnsupportedException When symfony/type-info cannot resolve the reflection type.
     */
    public function resolve(
        ReflectionParameter $parameter,
        ?string $whereConstraint,
        ?string $bindingField = null,
    ): UriParameterDescriptor {
        $type = $this->resolveType($parameter);
        $optional = $type->isNullable();

        // NullableType extends UnionType, unwrap to get the concrete inner type.
        $innerType = $type instanceof NullableType
            ? $type->getWrappedType()
            : $type;

        [$resolvedConstraint, $whereKind] = $this->resolveConstraint($whereConstraint);

        $modelBinding = $this->resolveModelBinding($innerType, $bindingField);
        $enumCases = $this->resolveEnumCases($innerType);

        return new UriParameterDescriptor(
            name: $parameter->getName(),
            type: $type,
            optional: $optional,
            whereConstraint: $resolvedConstraint,
            whereKind: $whereKind,
            enumCases: $enumCases,
            modelBinding: $modelBinding,
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
     * Resolves a route-model-bound parameter into a single {@see RouteModelBinding}: the bound
     * model, the key the route binds against (a `{param:field}` segment overrides the model's
     * `getRouteKeyName()`), and — only when that key is the model's own primary key — the type and
     * format the key carries.
     *
     * `BackedEnumType` extends `ObjectType` in symfony/type-info — check it first to avoid treating
     * an enum as a model binding.
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

            // Type the key only when the route binds by the model's own primary key — a custom
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
     * Reads the JSON-Schema type and format a model's primary key resolves against.
     *
     * `HasUuids` / `HasUlids` are detected by trait rather than via `getKeyType()`, because that
     * method's unique-id branch depends on an `usesUniqueIds` flag set only by the model
     * constructor — which {@see resolveModelBinding} deliberately bypasses. For plain models
     * `getKeyType()` reads the `$keyType` property default, which is reliable without the
     * constructor.
     *
     * @param class-string $className
     *
     * @return array{string, ?string} The JSON-Schema type and optional format.
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
