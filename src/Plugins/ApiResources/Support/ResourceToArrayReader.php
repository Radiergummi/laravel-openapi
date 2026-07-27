<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\PublicPropertyTypeReader;
use Radiergummi\OpenApi\Support\Generator\SchemaFromArrayDefinition;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\SchemaDefinitionFromLiteral;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

use function array_key_exists;
use function class_exists;
use function in_array;
use function is_a;
use function is_int;
use function is_string;
use function method_exists;
use function Radiergummi\OpenApi\is_defined;
use function str_starts_with;

/**
 * Reads the response fields of an Eloquent API Resource from its `toArray()` array literal.
 *
 * Applies only when `toArray()` is overridden outside the framework and its body is a single
 * straight-line `return [...]` literal ({@see SingleReturnArrayLiteralFinder}). Returns null
 * when that bounded case does not hold. Pure and registry-free; results are memoised per class
 * for the scoped lifetime.
 *
 * @internal
 */
#[Scoped]
final class ResourceToArrayReader
{
    public const string TO_ARRAY = 'toArray';

    private const string WHEN = 'when';

    private const string UNLESS = 'unless';

    private const string WHEN_LOADED = 'whenLoaded';

    private const string WHEN_COUNTED = 'whenCounted';

    private const string MERGE = 'merge';

    private const string MERGE_WHEN = 'mergeWhen';

    private const string FORMAT = 'format';

    /** The model-attribute formats that make a `->format()` receiver provably a date. */
    private const array DATE_LIKE_FORMATS = ['date', 'date-time'];

    /**
     * PHP's own `DATE_*` constants and their values. Resolving them from a closed map rather than
     * `defined()` keeps the emitted schema independent of which of the app's constant files
     * happen to be loaded: an app-defined `DATE_…` global must never change what is generated.
     *
     * @var array<string, string>
     */
    private const array DATE_CONSTANT_VALUES = [
        'DATE_ATOM' => 'Y-m-d\TH:i:sP',
        'DATE_COOKIE' => 'l, d-M-Y H:i:s T',
        'DATE_ISO8601' => 'Y-m-d\TH:i:sO',
        'DATE_ISO8601_EXPANDED' => 'X-m-d\TH:i:sP',
        'DATE_RFC822' => 'D, d M y H:i:s O',
        'DATE_RFC850' => 'l, d-M-y H:i:s T',
        'DATE_RFC1036' => 'D, d M y H:i:s O',
        'DATE_RFC1123' => 'D, d M Y H:i:s O',
        'DATE_RFC2822' => 'D, d M Y H:i:s O',
        'DATE_RFC3339' => 'Y-m-d\TH:i:sP',
        'DATE_RFC3339_EXTENDED' => 'Y-m-d\TH:i:s.vP',
        'DATE_RFC7231' => 'D, d M Y H:i:s \G\M\T',
        'DATE_RSS' => 'D, d M Y H:i:s O',
        'DATE_W3C' => 'Y-m-d\TH:i:sP',
    ];

    /**
     * Format strings that produce RFC3339, the shape OpenAPI's `date-time` promises. Everything
     * else stays an unrefined `string`: consumers generate parsers from `format`, so claiming a
     * shape the app does not emit is worse than claiming none.
     *
     * @var list<string>
     */
    private const array RFC3339_FORMATS = [
        // DATE_ATOM, DATE_RFC3339, DATE_W3C, DateTimeInterface::ATOM
        'Y-m-d\TH:i:sP',
        // DATE_RFC3339_EXTENDED; RFC3339 permits a fractional-second part
        'Y-m-d\TH:i:s.vP',
        'c',
    ];

    private const string DAY_FORMAT = 'Y-m-d';

    /**
     * Keyed by `class::method`, since a resource may expose several readable field bags
     * (`toArray()`, or JSON:API's `toAttributes()`/`toRelationships()`/…).
     *
     * @var array<string, null|InferredToArrayFields>
     */
    private array $cache = [];

    public function __construct(
        private readonly SingleReturnArrayLiteralFinder $returnLiteralFinder,
        private readonly WrappedModelLocator $wrappedModelLocator,
        private readonly EloquentModelToSchema $modelToSchema,
        private readonly PublicPropertyTypeReader $publicPropertyTypeReader,
    ) {}

    /**
     * @param class-string<JsonResource> $resourceClass
     * @param non-empty-string           $method        the array-returning method to read
     *
     * @throws ReflectionException
     */
    public function read(string $resourceClass, string $method = self::TO_ARRAY): ?InferredToArrayFields
    {
        $key = $resourceClass . '::' . $method;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->readFields($resourceClass, $method);
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     * @param non-empty-string           $method
     *
     * @throws ReflectionException
     */
    private function readFields(string $resourceClass, string $method): ?InferredToArrayFields
    {
        if (!$this->overridesMethod($resourceClass, $method)) {
            return null;
        }

        $literal = $this->returnLiteralFinder->find(new ReflectionMethod($resourceClass, $method));

        if ($literal === null) {
            return null;
        }

        $modelClass = $this->wrappedModelLocator->locate($resourceClass);

        $hasUnreadableMergePayload = false;
        $fields = $this->fieldsFromArrayNode(
            $literal,
            optional: false,
            resourceClass: $resourceClass,
            modelClass: $modelClass,
            unreadableMergePayload: $hasUnreadableMergePayload,
        );

        if ($fields === null) {
            return null;
        }

        return new InferredToArrayFields($fields, $hasUnreadableMergePayload);
    }

    /**
     * Whether the resource overrides `toArray()` outside the framework.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
     */
    public function overridesToArray(string $resourceClass): bool
    {
        return $this->overridesMethod($resourceClass, self::TO_ARRAY);
    }

    /**
     * Whether the resource declares the given method outside the framework.
     *
     * A method the app never overrides carries no app-specific shape, so reading the
     * framework's own declaration would document nothing useful.
     *
     * @param class-string<JsonResource> $resourceClass
     * @param non-empty-string           $method
     *
     * @throws ReflectionException
     */
    public function overridesMethod(string $resourceClass, string $method): bool
    {
        if (!method_exists($resourceClass, $method)) {
            return false;
        }

        $declaringClass = new ReflectionMethod($resourceClass, $method)->getDeclaringClass()->getName();

        return !str_starts_with($declaringClass, 'Illuminate\\');
    }

    // region Literal walking

    /**
     * Walks the keyed entries of the literal into fields. An unkeyed entry must be a
     * `merge()`/`mergeWhen()` spread; any other unkeyed entry, a spread, or a non-literal key
     * causes the whole literal to be refused (null) rather than partially documented.
     *
     * @param class-string<JsonResource> $resourceClass
     * @param null|class-string<Model>   $modelClass
     *
     * @return null|list<InferredResourceField>
     *
     * @throws ReflectionException
     */
    private function fieldsFromArrayNode(
        Array_ $array,
        bool $optional,
        string $resourceClass,
        ?string $modelClass,
        bool &$unreadableMergePayload,
    ): ?array {
        $fields = [];

        foreach ($array->items as $item) {
            if ($item->unpack) {
                return null;
            }

            if ($item->key === null) {
                $merged = $this->fieldsFromMergeCall(
                    $item->value,
                    $optional,
                    $resourceClass,
                    $modelClass,
                    $unreadableMergePayload,
                );

                if ($merged === null) {
                    return null;
                }

                $fields = [...$fields, ...$merged];

                continue;
            }

            try {
                $key = AstLiteralEvaluator::evaluate($item->key, $resourceClass);
            } catch (NonLiteralValueException) {
                return null;
            }

            if (!is_string($key) && !is_int($key)) {
                return null;
            }

            $fields[] = $this->resolveValue((string) $key, $item->value, $optional, $resourceClass, $modelClass);
        }

        return $fields;
    }

    /**
     * Fields inlined by a `$this->merge([...])` / `$this->mergeWhen(..., [...])` call, or an
     * empty list when the payload is not a literal (flagged as unreadable). Returns null when
     * the entry is not a merge call: a plain unkeyed entry makes the whole literal unknowable.
     *
     * @param class-string<JsonResource> $resourceClass
     * @param null|class-string<Model>   $modelClass
     *
     * @return null|list<InferredResourceField>
     *
     * @throws ReflectionException
     */
    private function fieldsFromMergeCall(
        Expr $value,
        bool $optional,
        string $resourceClass,
        ?string $modelClass,
        bool &$unreadableMergePayload,
    ): ?array {
        $methodName = $this->resourceMethodName($value);

        if ($methodName !== self::MERGE && $methodName !== self::MERGE_WHEN) {
            return null;
        }

        /** @var MethodCall $value */
        $payloadPosition = $methodName === self::MERGE ? 0 : 1;
        $payload = ($value->getArgs()[$payloadPosition] ?? null)?->value;

        if (!$payload instanceof Array_) {
            $unreadableMergePayload = true;

            return [];
        }

        $merged = $this->fieldsFromArrayNode(
            $payload,
            optional: $optional || $methodName === self::MERGE_WHEN,
            resourceClass: $resourceClass,
            modelClass: $modelClass,
            unreadableMergePayload: $unreadableMergePayload,
        );

        if ($merged === null) {
            $unreadableMergePayload = true;

            return [];
        }

        return $merged;
    }

    // endregion

    // region Value resolution

    /**
     * The method name of a `$this->method(...)` or `$this->resource->method(...)` call, or null.
     */
    private function resourceMethodName(Expr $value): ?string
    {
        if (!$value instanceof MethodCall || $value->isFirstClassCallable() || !$value->name instanceof Identifier) {
            return null;
        }

        return $this->isResourceReceiver($value->var) ? $value->name->toString() : null;
    }

    /**
     * Whether the receiver is `$this` or `$this->resource` (both refer to the wrapped model).
     */
    private function isResourceReceiver(Expr $receiver): bool
    {
        if ($this->isThisVariable($receiver)) {
            return true;
        }

        return $receiver instanceof PropertyFetch
            && $this->isThisVariable($receiver->var)
            && $receiver->name instanceof Identifier
            && $receiver->name->toString() === 'resource';
    }

    private function isThisVariable(Expr $expression): bool
    {
        return $expression instanceof Variable && $expression->name === 'this';
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     * @param null|class-string<Model>   $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveValue(
        string $name,
        Expr $value,
        bool $optional,
        string $resourceClass,
        ?string $modelClass,
    ): InferredResourceField {
        $wrapped = $this->resolveConditionalWrapper($name, $value, $resourceClass, $modelClass);

        if ($wrapped !== null) {
            return $wrapped;
        }

        $nested = $this->resolveNestedResource($name, $value, $optional);

        if ($nested !== null) {
            return $nested;
        }

        $valueObjectProperty = $this->resolveValueObjectProperty($name, $value, $optional, $resourceClass);

        if ($valueObjectProperty !== null) {
            return $valueObjectProperty;
        }

        $modelProperty = $this->resolveModelProperty($name, $value, $optional, $resourceClass, $modelClass);

        if ($modelProperty !== null) {
            return $modelProperty;
        }

        $formattedDate = $this->resolveFormattedDate($name, $value, $optional, $resourceClass, $modelClass);

        if ($formattedDate !== null) {
            return $formattedDate;
        }

        $definition = SchemaDefinitionFromLiteral::fromValue($value);

        if ($definition !== []) {
            return InferredResourceField::ofProperty(
                $name,
                required: !$optional,
                property: SchemaFromArrayDefinition::buildProperty($name, $definition),
            );
        }

        return $this->unconstrained($name, $optional);
    }

    /**
     * Resolves `$this->when()`, `$this->unless()`, `$this->whenLoaded()`, `$this->whenCounted()`,
     * and any other `when`-prefixed resource method as an optional field (presence is
     * runtime-decided).
     *
     * @param class-string<JsonResource> $resourceClass
     * @param null|class-string<Model>   $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveConditionalWrapper(
        string $name,
        Expr $value,
        string $resourceClass,
        ?string $modelClass,
    ): ?InferredResourceField {
        $methodName = $this->resourceMethodName($value);

        if ($methodName === null || !$this->isConditionalWrapperName($methodName)) {
            return null;
        }

        /** @var MethodCall $value */
        $arguments = $value->getArgs();

        if (($methodName === self::WHEN || $methodName === self::UNLESS) && isset($arguments[1])) {
            // Condition is never analysed; use the value argument only. unless() is when()'s inverse:
            // both carry the value at index 1.
            return $this->resolveValue(
                $name,
                $arguments[1]->value,
                optional: true,
                resourceClass: $resourceClass,
                modelClass: $modelClass,
            );
        }

        if ($methodName === self::WHEN_LOADED) {
            if (isset($arguments[1])) {
                return $this->resolveValue(
                    $name,
                    $arguments[1]->value,
                    optional: true,
                    resourceClass: $resourceClass,
                    modelClass: $modelClass,
                );
            }

            $relationProperty = $this->modelPropertyFromRelationArgument($name, $arguments[0] ?? null, $modelClass);

            if ($relationProperty !== null) {
                return $relationProperty;
            }
        }

        if ($methodName === self::WHEN_COUNTED) {
            return InferredResourceField::ofProperty(
                $name,
                required: false,
                property: new OA\Property(['property' => $name, 'type' => 'integer']),
            );
        }

        return $this->unconstrained($name, optional: true);
    }

    /**
     * Whether the method name is a conditional-presence wrapper: any `when`-prefixed method
     * (`when`, `whenLoaded`, `whenCounted`, …) or its inverse `unless`.
     */
    private function isConditionalWrapperName(string $name): bool
    {
        return str_starts_with($name, self::WHEN) || $name === self::UNLESS;
    }

    // endregion

    // region Node shapes

    /**
     * Resolves a bare `whenLoaded('relation')` call against the wrapped model's metadata.
     *
     * @param null|class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function modelPropertyFromRelationArgument(
        string $name,
        ?Arg $argument,
        ?string $modelClass,
    ): ?InferredResourceField {
        if ($argument === null || $modelClass === null) {
            return null;
        }

        try {
            $relation = AstLiteralEvaluator::evaluate($argument->value);
        } catch (NonLiteralValueException) {
            return null;
        }

        if (!is_string($relation)) {
            return null;
        }

        $property = $this->modelToSchema->propertyFor($modelClass, $relation);

        if ($property === null) {
            return null;
        }

        $property->property = $name;

        return InferredResourceField::ofProperty($name, required: false, property: $property);
    }

    private function unconstrained(string $name, bool $optional): InferredResourceField
    {
        return InferredResourceField::ofUnconstrained(
            $name,
            required: !$optional,
            property: new OA\Property(['property' => $name]),
        );
    }

    /**
     * Resolves `new X(...)`, `X::make(...)`, or `X::collection(...)` where `X` is a
     * `JsonResource` subclass. A conditional wrapper in the first argument marks the field optional.
     */
    private function resolveNestedResource(string $name, Expr $value, bool $optional): ?InferredResourceField
    {
        [$resourceClass, $isCollection, $arguments] = match (true) {
            $value instanceof New_ && $value->class instanceof Name
            => [$value->class->toString(), false, $value->isFirstClassCallable() ? [] : $value->getArgs()],
            $value instanceof StaticCall
            && $value->class instanceof Name
            && $value->name instanceof Identifier
            && !$value->isFirstClassCallable()
            && ($value->name->toString() === 'make' || $value->name->toString() === 'collection')
            => [$value->class->toString(), $value->name->toString() === 'collection', $value->getArgs()],
            default => [null, false, []],
        };

        if ($resourceClass === null || !class_exists($resourceClass)) {
            return null;
        }

        if (!is_a($resourceClass, JsonResource::class, allow_string: true)) {
            return null;
        }

        $firstArgument = ($arguments[0] ?? null)?->value;
        $wrapperName = $firstArgument !== null ? $this->resourceMethodName($firstArgument) : null;
        $conditional = $wrapperName !== null && $this->isConditionalWrapperName($wrapperName);

        /** @var class-string<JsonResource> $resourceClass */
        return InferredResourceField::ofNestedResource(
            $name,
            required: !$optional && !$conditional,
            resourceClass: $resourceClass,
            isCollection: $isCollection,
        );
    }

    /**
     * Resolves a `$this->field` / `$this->resource->field` reference against the wrapped model's
     * metadata. When the wrapped class is a non-Model value object, the field is typed from its
     * public property instead. Falls back to unconstrained when neither yields a type.
     *
     * @param class-string<JsonResource> $resourceClass
     * @param null|class-string<Model>   $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveModelProperty(
        string $name,
        Expr $value,
        bool $optional,
        string $resourceClass,
        ?string $modelClass,
    ): ?InferredResourceField {
        $fieldName = $this->modelFieldName($value);

        if ($fieldName === null) {
            return null;
        }

        $property = $modelClass !== null ? $this->modelToSchema->propertyFor($modelClass, $fieldName) : null;

        // The Model path is tried first; a non-Model value object is the fallback for shape (B).
        $property ??= $this->valueObjectProperty($resourceClass, $fieldName);

        if ($property === null) {
            return $this->unconstrained($name, $optional);
        }

        $property->property = $name;

        return InferredResourceField::ofProperty($name, required: !$optional, property: $property);
    }

    /**
     * Resolves `$this->field->format(...)` on a model attribute the metadata already types as a
     * date into a string, refined to `date-time` / `date` when the format argument resolves to a
     * shape OpenAPI names. A non-nullsafe call proves the receiver was present, so the null member
     * a nullable timestamp carries is dropped; `?->format(...)` keeps it.
     *
     * The date evidence is required: `->format()` on anything else is an app method that may
     * return anything. It may come from the wrapped model or from a value object's statically
     * typed public property, the same two sources a bare read consults.
     *
     * @param class-string<JsonResource> $resourceClass
     * @param null|class-string<Model>   $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveFormattedDate(
        string $name,
        Expr $value,
        bool $optional,
        string $resourceClass,
        ?string $modelClass,
    ): ?InferredResourceField {
        if (!$value instanceof MethodCall && !$value instanceof NullsafeMethodCall) {
            return null;
        }

        if (
            $value->isFirstClassCallable()
            || !$value->name instanceof Identifier
            || $value->name->toLowerString() !== self::FORMAT
        ) {
            return null;
        }

        $receiverProperty = $this->receiverProperty($value->var, $resourceClass, $modelClass);

        if (
            $receiverProperty === null
            || !in_array($receiverProperty->format, self::DATE_LIKE_FORMATS, strict: true)
        ) {
            return null;
        }

        $format = $this->formatArgumentValue($value, $resourceClass);
        $property = new OA\Property([
            'property' => $name,
            'type' => $value instanceof NullsafeMethodCall ? ['string', 'null'] : 'string',
            ...match (true) {
                in_array($format, self::RFC3339_FORMATS, strict: true) => ['format' => 'date-time'],
                $format === self::DAY_FORMAT => ['format' => 'date'],
                default => [],
            },
        ]);

        // The receiver's documented prose describes the attribute, which is still what this key holds.
        if (is_defined($receiverProperty->description)) {
            $property->description = $receiverProperty->description;
        }

        return InferredResourceField::ofProperty($name, required: !$optional, property: $property);
    }

    /**
     * The property schema behind a `->format(...)` receiver, from the wrapped model, from the
     * `@mixin`/`@extends` value object, or from a value object the resource declares as a typed
     * property. Null when no source types the receiver.
     *
     * @param class-string<JsonResource> $resourceClass
     * @param null|class-string<Model>   $modelClass
     *
     * @throws ReflectionException
     */
    private function receiverProperty(Expr $receiver, string $resourceClass, ?string $modelClass): ?OA\Property
    {
        $fieldName = $this->modelFieldName($receiver);

        // A wrapped-model read is tried first, mirroring the bare-read precedence. The branches
        // cannot overlap anyway: shape (A) needs a typed property, and the inherited `$resource`
        // one cannot be typed by a subclass.
        if ($fieldName !== null) {
            $property = $modelClass !== null ? $this->modelToSchema->propertyFor($modelClass, $fieldName) : null;

            return $property ?? $this->valueObjectProperty($resourceClass, $fieldName);
        }

        return $this->wrappedValueObjectProperty($receiver, $resourceClass);
    }

    /**
     * The literal format string a `format(...)` call was given, or null when the argument is
     * absent or not resolvable at compile time.
     *
     * @param class-string<JsonResource> $resourceClass
     */
    private function formatArgumentValue(MethodCall|NullsafeMethodCall $call, string $resourceClass): ?string
    {
        // Read positionally rather than through CallArgumentResolver: format() declares a single
        // parameter, so the named form `format(format: …)` lands at position 0 as well.
        $argument = ($call->getArgs()[0] ?? null)?->value;

        if ($argument === null) {
            return null;
        }

        if ($argument instanceof ConstFetch) {
            return self::DATE_CONSTANT_VALUES[$argument->name->toString()] ?? null;
        }

        try {
            $format = AstLiteralEvaluator::evaluate($argument, $resourceClass);
        } catch (NonLiteralValueException) {
            return null;
        }

        return is_string($format) ? $format : null;
    }

    /**
     * Resolves a shape (A) field, `$this-><wrappedProp>-><field>`, to its named property.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
     */
    private function resolveValueObjectProperty(
        string $name,
        Expr $value,
        bool $optional,
        string $resourceClass,
    ): ?InferredResourceField {
        $property = $this->wrappedValueObjectProperty($value, $resourceClass);

        if ($property === null) {
            return null;
        }

        $property->property = $name;

        return InferredResourceField::ofProperty($name, required: !$optional, property: $property);
    }

    /**
     * The shape (A) property behind `$this-><wrappedProp>-><field>`, typed from the value object
     * declared as `<wrappedProp>`'s type on the resource. Null when the expression is not that
     * shape or the field cannot be typed without guessing.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
     */
    private function wrappedValueObjectProperty(Expr $value, string $resourceClass): ?OA\Property
    {
        if (
            !$value instanceof PropertyFetch
            || !$value->name instanceof Identifier
            || !$value->var instanceof PropertyFetch
            || !$value->var->name instanceof Identifier
            || !$this->isThisVariable($value->var->var)
        ) {
            return null;
        }

        $valueObjectClass = $this->propertyTypeClass($resourceClass, $value->var->name->toString());

        if ($valueObjectClass === null) {
            return null;
        }

        return $this->publicPropertyTypeReader->propertyFor($valueObjectClass, $value->name->toString());
    }

    /**
     * Shape (B) value-object property: the wrapped class from `@mixin`/`@extends` is a non-Model,
     * typed by its public property. Returns null when there is no value object or no typed property.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
     */
    private function valueObjectProperty(string $resourceClass, string $fieldName): ?OA\Property
    {
        $valueObjectClass = $this->wrappedModelLocator->locateValueObject($resourceClass);

        if ($valueObjectClass === null) {
            return null;
        }

        return $this->publicPropertyTypeReader->propertyFor($valueObjectClass, $fieldName);
    }

    /**
     * The single named class a property is statically typed as on the given class, or null when
     * the property is missing, untyped, or a union/intersection (not a single known class).
     *
     * @param class-string $class
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function propertyTypeClass(string $class, string $propertyName): ?string
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->hasProperty($propertyName)) {
            return null;
        }

        $type = $reflection->getProperty($propertyName)->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();

        return class_exists($className) ? $className : null;
    }

    /**
     * The field name from a `$this->field` or `$this->resource->field` fetch, or null.
     * `$this->resource` itself is not a resolvable field.
     */
    private function modelFieldName(Expr $value): ?string
    {
        if (!$value instanceof PropertyFetch || !$value->name instanceof Identifier) {
            return null;
        }

        $fieldName = $value->name->toString();

        if (!$this->isResourceReceiver($value->var)) {
            return null;
        }

        return $fieldName === 'resource' && $this->isThisVariable($value->var) ? null : $fieldName;
    }

    // endregion
}
