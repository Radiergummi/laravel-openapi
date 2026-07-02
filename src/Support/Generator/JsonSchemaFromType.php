<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Routing\UrlRoutable;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Support\Provenance\SchemaProvenance;
use Ramsey\Uuid\UuidInterface;
use ReflectionEnumBackedCase;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ArrayShapeType;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\GenericType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeIdentifier;

use function array_filter;
use function array_map;
use function explode;
use function implode;
use function in_array;
use function is_a;
use function is_int;
use function ltrim;
use function preg_replace;
use function Radiergummi\OpenApi\class_resource_name;
use function Radiergummi\OpenApi\copy_schema_fields;
use function sprintf;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const PHP_EOL;

/**
 * Converts a symfony/type-info {@see Type} tree into a swagger-php {@see OA\Schema}.
 *
 * This is the package's single docblock/type → schema engine. It covers scalars, backed/unit enums,
 * DateTime/UUID/UrlRoutable object formats, unions, and the structural shapes that carry over from
 * PHPDoc: array shapes (`array{…}`), lists (`list<T>`, `T[]`), and string-keyed maps
 * (`array<string, T>`, `Collection<string, T>`).
 *
 * Subclass ordering is load-bearing: {@see NullableType} before {@see UnionType} (it extends it),
 * {@see BackedEnumType} before {@see EnumType}/{@see ObjectType}, and {@see ArrayShapeType} before
 * {@see CollectionType} (it extends it).
 *
 * Leaf classes (a bare object or backed enum) can be routed through a caller-supplied
 * `callable(string $fqcn): ?OA\Schema` so consumer policy (`$ref` vs. inline) stays outside this
 * class. When the callback is absent, or returns null, the built-in leaf handling applies — this
 * default path is byte-identical to the class's behaviour before the callback existed.
 *
 * @internal
 */
#[Scoped]
final readonly class JsonSchemaFromType
{
    /**
     * Collection classes whose generics carry array key semantics (`Collection<K, V>` behaves like
     * `array<K, V>`). Matched by lowercased short name, since PHPDoc may write `Collection`,
     * `\Illuminate\Support\Collection`, or `EloquentCollection`.
     */
    private const array COLLECTION_CLASSES = [
        'collection',
        'eloquentcollection',
        'lazycollection',
        'enumerable',
    ];

    public function __construct(
        private LoggerInterface $logger,
        private ComponentSchemaRegistry $registry,
    ) {}

    /**
     * @param null|callable(string): ?OA\Schema $leafClassSchema resolves a leaf class FQCN to a
     *                                                           schema; when it returns non-null it
     *                                                           wins over the built-in leaf handling
     */
    public function fromType(Type $type, ?callable $leafClassSchema = null): OA\Schema
    {
        if ($type instanceof NullableType) {
            return NullableSchema::wrap($this->fromType($type->getWrappedType(), $leafClassSchema));
        }

        if ($type instanceof UnionType) {
            return new OA\Schema([
                'oneOf' => array_map(
                    fn(Type $member): OA\Schema => $this->fromType($member, $leafClassSchema),
                    $type->getTypes(),
                ),
            ]);
        }

        if ($type instanceof BackedEnumType) {
            /** @var class-string<BackedEnum> $className */
            $className = $type->getClassName();

            return $this->leafOrBuiltin($className, $leafClassSchema)
                ?? $this->fromBackedEnumComponent($className);
        }

        if ($type instanceof EnumType) {
            return new OA\Schema([
                'type' => 'string',
                'description' => sprintf(
                    'Values of unit enum %s are not representable as a JSON primitive.',
                    class_resource_name($type->getClassName()),
                ),
            ]);
        }

        // ArrayShapeType extends CollectionType, so it must be matched first.
        if ($type instanceof ArrayShapeType) {
            return $this->fromArrayShape($type, $leafClassSchema);
        }

        if ($type instanceof CollectionType) {
            return $this->fromCollectionType($type, $leafClassSchema);
        }

        if ($type instanceof ObjectType) {
            return $this->leafOrBuiltin($type->getClassName(), $leafClassSchema)
                ?? $this->fromObjectType($type);
        }

        if ($type instanceof BuiltinType) {
            return $this->fromBuiltinType($type);
        }

        // Fallback for any exotic Type subclass not handled above (IntersectionType, ObjectShapeType,
        // TemplateType). Never crashes; degrades to an untyped-string note.
        $this->logger->warning(sprintf('Unmapped Type subclass: %s', $type::class));

        return new OA\Schema([
            'type' => 'string',
            'description' => sprintf('Unmapped type: %s', $type::class),
        ]);
    }

    /**
     * The caller-supplied leaf schema for a class, or null when no callback is given or it declines.
     *
     * @param null|callable(string): ?OA\Schema $leafClassSchema
     */
    private function leafOrBuiltin(string $className, ?callable $leafClassSchema): ?OA\Schema
    {
        return $leafClassSchema === null ? null : $leafClassSchema($className);
    }

    /**
     * Promotes a backed enum to a reusable component and returns a `$ref` schema.
     *
     * @param class-string<BackedEnum> $enumClass
     */
    public function fromBackedEnumComponent(string $enumClass): OA\Schema
    {
        return new OA\Schema(['ref' => $this->backedEnumComponentReference($enumClass)]);
    }

    /**
     * Registers the backed enum's component (idempotently) and returns the `$ref` pointer string.
     *
     * @param class-string<BackedEnum> $enumClass
     */
    public function backedEnumComponentReference(string $enumClass): string
    {
        $key = $this->registry->buildOnce(
            $enumClass,
            fn(): OA\Schema => $this->fromBackedEnumClass($enumClass),
            new SchemaProvenance(self::class),
        );

        return $this->registry->qualifyKey($key);
    }

    /**
     * Useful when the class name comes from a cast string rather than a resolved type tree;
     * determines integer-vs-string backing via reflection.
     *
     * @param class-string<BackedEnum> $enumClass
     */
    public function fromBackedEnumClass(string $enumClass): OA\Schema
    {
        $cases = $enumClass::cases();
        $isInt = $cases !== [] && is_int($cases[0]->value);

        $props = [
            'type' => $isInt ? 'integer' : 'string',
            'enum' => array_map(
                static fn(BackedEnum $case): int|string
                    => $isInt
                    ? (int) $case->value
                    : (string) $case->value,
                $cases,
            ),
        ];

        $caseDescription = $this->enumCaseDescription($enumClass, $cases);

        if ($caseDescription !== null) {
            $props['description'] = $caseDescription;
        }

        return new OA\Schema($props);
    }

    /**
     * Returns a Markdown list of enum cases with their PHPDoc summaries, or null if none are documented.
     *
     * @param class-string<BackedEnum> $enumClass
     * @param list<BackedEnum>         $cases
     */
    private function enumCaseDescription(string $enumClass, array $cases): ?string
    {
        $lines = [];

        foreach ($cases as $case) {
            $constant = new ReflectionEnumBackedCase($enumClass, $case->name);
            $comment = $constant->getDocComment();

            if ($comment === false) {
                continue;
            }

            // Strip /** … */ scaffolding and leading * from each line.
            $raw = preg_replace('/^\s*\/\*\*|\*\/\s*$/', '', $comment) ?? $comment;
            $stripped = array_map(
                static fn(string $line): string => trim(ltrim($line, " \t*")),
                explode(PHP_EOL, $raw),
            );
            $summary = implode(
                ' ',
                array_filter(
                    $stripped,
                    static fn(string $line): bool => $line !== '' && !str_starts_with($line, '@'),
                ),
            );

            if ($summary === '') {
                continue;
            }

            $lines[] = sprintf('- `%s`: %s', $case->value, $summary);
        }

        return $lines !== [] ? implode(PHP_EOL, $lines) : null;
    }

    /**
     * An `array{…}` shape → an object schema with typed properties. The shape is `ksort`ed by
     * symfony's constructor, so properties serialize in sorted-key order. An open shape
     * (`array{…, ...}`) contributes an `additionalProperties` value schema from its extra value type.
     *
     * @param null|callable(string): ?OA\Schema $leafClassSchema
     */
    private function fromArrayShape(ArrayShapeType $type, ?callable $leafClassSchema): OA\Schema
    {
        $properties = [];
        $required = [];

        foreach ($type->getShape() as $key => $field) {
            $name = (string) $key;
            $properties[] = $this->propertyFromSchema(
                $name,
                $this->fromType($field['type'], $leafClassSchema),
            );

            if (!$field['optional']) {
                $required[] = $name;
            }
        }

        $arguments = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $arguments['required'] = $required;
        }

        if (!$type->isSealed()) {
            $extraValueType = $type->getExtraValueType();

            $arguments['additionalProperties'] = $extraValueType === null
                ? true
                : $this->fromType($extraValueType, $leafClassSchema);
        }

        return new OA\Schema($arguments);
    }

    /**
     * A `CollectionType` (`array<K, V>`, `list<T>`, `T[]`, `Collection<K, V>`, …) → a list or a map.
     * An integer-keyed collection is a list (`{type: array, items: <V>}`); a string-keyed one is a
     * map (`{type: object, additionalProperties: <V>}`). Only array/iterable builtins and the known
     * collection classes are array-like; any other generic class (`JsonResource<int>`) is not, and
     * degrades to the unmapped fallback.
     *
     * @param CollectionType<BuiltinType<TypeIdentifier::ARRAY>|BuiltinType<TypeIdentifier::ITERABLE>|ObjectType<class-string>> $type
     * @param null|callable(string): ?OA\Schema                                                                                 $leafClassSchema
     */
    private function fromCollectionType(CollectionType $type, ?callable $leafClassSchema): OA\Schema
    {
        if (!$this->isArrayLikeCollection($type)) {
            $this->logger->warning(sprintf('Unmapped generic type: %s', (string) $type));

            return new OA\Schema([
                'type' => 'string',
                'description' => sprintf('Unmapped type: %s', (string) $type),
            ]);
        }

        $valueType = $type->getCollectionValueType();

        if ($this->isMap($type)) {
            return new OA\Schema([
                'type' => 'object',
                'additionalProperties' => $this->mapValueSchema($valueType, $leafClassSchema),
            ]);
        }

        // A `mixed` element (bare `array`, `list<mixed>`) has no item shape: emit an empty items
        // schema rather than the unmapped-builtin stub the `mixed` scalar would otherwise produce.
        if ($valueType->isIdentifiedBy(TypeIdentifier::MIXED)) {
            return new OA\Schema(['type' => 'array', 'items' => new OA\Items([])]);
        }

        return $this->listOf($this->fromType($valueType, $leafClassSchema));
    }

    /**
     * A string-keyed collection is a map; an integer-keyed one is a list. `array<int|string, V>`
     * (the shape `T[]` and `iterable<V>` resolve to) is a list, matching the phpstan path's treatment
     * of `T[]` / `iterable<T>`.
     *
     * @param CollectionType<BuiltinType<TypeIdentifier::ARRAY>|BuiltinType<TypeIdentifier::ITERABLE>|ObjectType<class-string>> $type
     */
    private function isMap(CollectionType $type): bool
    {
        $keyType = $type->getCollectionKeyType();

        return $keyType->isIdentifiedBy(TypeIdentifier::STRING)
            && !$keyType->isIdentifiedBy(TypeIdentifier::INT);
    }

    /**
     * A `mixed`-valued map is a permissive map (`additionalProperties: true`), matching the Spatie
     * Data path; otherwise the value type's schema.
     *
     * @param null|callable(string): ?OA\Schema $leafClassSchema
     */
    private function mapValueSchema(Type $valueType, ?callable $leafClassSchema): OA\Schema|bool
    {
        if ($valueType->isIdentifiedBy(TypeIdentifier::MIXED)) {
            return true;
        }

        return $this->fromType($valueType, $leafClassSchema);
    }

    /**
     * Whether the collection wraps an array/iterable builtin or one of the recognised collection
     * classes; a generic over any other class (e.g. `JsonResource<int>`) is not array-like.
     *
     * @param CollectionType<BuiltinType<TypeIdentifier::ARRAY>|BuiltinType<TypeIdentifier::ITERABLE>|ObjectType<class-string>> $type
     */
    private function isArrayLikeCollection(CollectionType $type): bool
    {
        if ($type->isIdentifiedBy(TypeIdentifier::ARRAY) || $type->isIdentifiedBy(TypeIdentifier::ITERABLE)) {
            return true;
        }

        $wrapped = $type->getWrappedType();

        while ($wrapped instanceof CollectionType) {
            $wrapped = $wrapped->getWrappedType();
        }

        if ($wrapped instanceof GenericType) {
            $wrapped = $wrapped->getWrappedType();
        }

        if (!$wrapped instanceof ObjectType) {
            return false;
        }

        $className = $wrapped->getClassName();
        $separator = strrpos($className, '\\');
        $shortName = $separator === false ? $className : substr($className, $separator + 1);

        return in_array(strtolower($shortName), self::COLLECTION_CLASSES, strict: true);
    }

    private function propertyFromSchema(string $name, OA\Schema $schema): OA\Property
    {
        return copy_schema_fields($schema, new OA\Property(['property' => $name]));
    }

    /** swagger-php rejects `type: array` without `items`, so items is always emitted. */
    private function listOf(OA\Schema $element): OA\Schema
    {
        $items = new OA\Items([]);
        copy_schema_fields($element, $items);

        return new OA\Schema(['type' => 'array', 'items' => $items]);
    }

    /** @param ObjectType<class-string> $type */
    private function fromObjectType(ObjectType $type): OA\Schema
    {
        $className = $type->getClassName();

        if (is_a($className, DateTimeInterface::class, allow_string: true)) {
            return new OA\Schema(['type' => 'string', 'format' => 'date-time']);
        }

        if (is_a($className, UuidInterface::class, allow_string: true)) {
            return new OA\Schema(['type' => 'string', 'format' => 'uuid']);
        }

        if (is_a($className, UrlRoutable::class, allow_string: true)) {
            return new OA\Schema(['type' => 'string']);
        }

        $this->logger->warning(sprintf('Unmapped object type: %s', $className));

        return new OA\Schema([
            'type' => 'string',
            'description' => sprintf('Unmapped object type: %s', class_resource_name($className)),
        ]);
    }

    /** @param BuiltinType<TypeIdentifier> $type */
    private function fromBuiltinType(BuiltinType $type): OA\Schema
    {
        return match ($type->getTypeIdentifier()) {
            TypeIdentifier::STRING => new OA\Schema(['type' => 'string']),
            TypeIdentifier::INT => new OA\Schema(['type' => 'integer']),
            TypeIdentifier::FLOAT => new OA\Schema(['type' => 'number']),
            TypeIdentifier::BOOL,
            TypeIdentifier::TRUE,
            TypeIdentifier::FALSE => new OA\Schema(['type' => 'boolean']),
            TypeIdentifier::ARRAY => new OA\Schema(['type' => 'array', 'items' => new OA\Items([])]),
            // `mixed` carries no shape: an untyped (empty) schema, matching the former phpstan path
            // that returned null here and let the caller emit a present-but-untyped property.
            TypeIdentifier::MIXED => new OA\Schema([]),
            default => $this->unmappedBuiltin($type),
        };
    }

    /** @param BuiltinType<TypeIdentifier> $type */
    private function unmappedBuiltin(BuiltinType $type): OA\Schema
    {
        $name = $type->getTypeIdentifier()->value;
        $this->logger->warning(sprintf('Unmapped builtin type: %s', $name));

        return new OA\Schema([
            'type' => 'string',
            'description' => sprintf('Unmapped builtin type: %s', $name),
        ]);
    }
}
