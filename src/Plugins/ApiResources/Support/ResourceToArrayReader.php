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
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\SchemaFromArrayDefinition;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\SchemaDefinitionFromLiteral;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use ReflectionException;
use ReflectionMethod;

use function array_key_exists;
use function class_exists;
use function is_a;
use function is_int;
use function is_string;
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
    private const string WHEN = 'when';

    private const string WHEN_LOADED = 'whenLoaded';

    private const string WHEN_COUNTED = 'whenCounted';

    private const string MERGE = 'merge';

    private const string MERGE_WHEN = 'mergeWhen';

    /**
     * @var array<class-string<JsonResource>, null|InferredToArrayFields>
     */
    private array $cache = [];

    public function __construct(
        private readonly SingleReturnArrayLiteralFinder $returnLiteralFinder,
        private readonly WrappedModelLocator $wrappedModelLocator,
        private readonly EloquentModelToSchema $modelToSchema,
    ) {}

    /**
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
     */
    public function read(string $resourceClass): ?InferredToArrayFields
    {
        if (array_key_exists($resourceClass, $this->cache)) {
            return $this->cache[$resourceClass];
        }

        return $this->cache[$resourceClass] = $this->readFields($resourceClass);
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
     */
    private function readFields(string $resourceClass): ?InferredToArrayFields
    {
        if (!$this->overridesToArray($resourceClass)) {
            return null;
        }

        $literal = $this->returnLiteralFinder->find(new ReflectionMethod($resourceClass, 'toArray'));

        if ($literal === null) {
            return null;
        }

        $modelClass = $this->wrappedModelLocator->locate($resourceClass);

        $hasUnreadableMergePayload = false;
        $fields = $this->fieldsFromArrayNode(
            $literal,
            optional: false,
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
     */
    public function overridesToArray(string $resourceClass): bool
    {
        $declaringClass = new ReflectionMethod($resourceClass, 'toArray')->getDeclaringClass()->getName();

        return !str_starts_with($declaringClass, 'Illuminate\\');
    }

    // region Literal walking

    /**
     * Walks the keyed entries of the literal into fields. An unkeyed entry must be a
     * `merge()`/`mergeWhen()` spread; any other unkeyed entry, a spread, or a non-literal key
     * causes the whole literal to be refused (null) rather than partially documented.
     *
     * @param null|class-string<Model> $modelClass
     *
     * @return null|list<InferredResourceField>
     *
     * @throws ReflectionException
     */
    private function fieldsFromArrayNode(
        Array_ $array,
        bool $optional,
        ?string $modelClass,
        bool &$unreadableMergePayload,
    ): ?array {
        $fields = [];

        foreach ($array->items as $item) {
            if ($item->unpack) {
                return null;
            }

            if ($item->key === null) {
                $merged = $this->fieldsFromMergeCall($item->value, $optional, $modelClass, $unreadableMergePayload);

                if ($merged === null) {
                    return null;
                }

                $fields = [...$fields, ...$merged];

                continue;
            }

            try {
                $key = AstLiteralEvaluator::evaluate($item->key);
            } catch (NonLiteralValueException) {
                return null;
            }

            if (!is_string($key) && !is_int($key)) {
                return null;
            }

            $fields[] = $this->resolveValue((string) $key, $item->value, $optional, $modelClass);
        }

        return $fields;
    }

    /**
     * Fields inlined by a `$this->merge([...])` / `$this->mergeWhen(..., [...])` call, or an
     * empty list when the payload is not a literal (flagged as unreadable). Returns null when
     * the entry is not a merge call: a plain unkeyed entry makes the whole literal unknowable.
     *
     * @param null|class-string<Model> $modelClass
     *
     * @return null|list<InferredResourceField>
     *
     * @throws ReflectionException
     */
    private function fieldsFromMergeCall(
        Expr $value,
        bool $optional,
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
     * @param null|class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveValue(string $name, Expr $value, bool $optional, ?string $modelClass): InferredResourceField
    {
        $wrapped = $this->resolveConditionalWrapper($name, $value, $modelClass);

        if ($wrapped !== null) {
            return $wrapped;
        }

        $nested = $this->resolveNestedResource($name, $value, $optional);

        if ($nested !== null) {
            return $nested;
        }

        $modelProperty = $this->resolveModelProperty($name, $value, $optional, $modelClass);

        if ($modelProperty !== null) {
            return $modelProperty;
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
     * Resolves `$this->when()`, `$this->whenLoaded()`, `$this->whenCounted()`, and any other
     * `when`-prefixed resource method as an optional field (presence is runtime-decided).
     *
     * @param null|class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveConditionalWrapper(string $name, Expr $value, ?string $modelClass): ?InferredResourceField
    {
        $methodName = $this->resourceMethodName($value);

        if ($methodName === null || !str_starts_with($methodName, self::WHEN)) {
            return null;
        }

        /** @var MethodCall $value */
        $arguments = $value->getArgs();

        if ($methodName === self::WHEN && isset($arguments[1])) {
            // Condition is never analysed; use the value argument only.
            return $this->resolveValue($name, $arguments[1]->value, optional: true, modelClass: $modelClass);
        }

        if ($methodName === self::WHEN_LOADED) {
            if (isset($arguments[1])) {
                return $this->resolveValue($name, $arguments[1]->value, optional: true, modelClass: $modelClass);
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
        $conditional = $wrapperName !== null && str_starts_with($wrapperName, self::WHEN);

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
     * metadata. Falls back to unconstrained when the model or field is unknown.
     *
     * @param null|class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveModelProperty(
        string $name,
        Expr $value,
        bool $optional,
        ?string $modelClass,
    ): ?InferredResourceField {
        $fieldName = $this->modelFieldName($value);

        if ($fieldName === null) {
            return null;
        }

        $property = $modelClass !== null ? $this->modelToSchema->propertyFor($modelClass, $fieldName) : null;

        if ($property === null) {
            return $this->unconstrained($name, $optional);
        }

        $property->property = $name;

        return InferredResourceField::ofProperty($name, required: !$optional, property: $property);
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
