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
 * Reads the response fields of an Eloquent API Resource from its `toArray()` array literal —
 * the Tier-1 bounded scan of issue #12 (epic #5).
 *
 * The bounded case: `toArray()` is overridden (outside the framework) and its body is a single
 * straight-line `return [...]` literal ({@see SingleReturnArrayLiteralFinder}). The literal's
 * string keys become fields; each value resolves best-effort, refusing per value:
 *
 * - `$this->field` / `$this->resource->field` → the wrapped model's property schema
 *   ({@see WrappedModelLocator} + {@see EloquentModelToSchema::propertyFor()}),
 * - literal scalars and arrays → typed via {@see SchemaDefinitionFromLiteral},
 * - `new OtherResource(...)` / `OtherResource::make(...)` / `OtherResource::collection(...)` →
 *   a nested-resource reference the schema builder turns into a `$ref` (collection-wrapped for
 *   `::collection`); a conditional wrapper inside the constructor argument
 *   (`new X($this->whenLoaded('relation'))`) marks the field optional,
 * - `$this->when(...)` / `$this->whenLoaded(...)` → the inner value, optional — the condition
 *   is never analysed; a bare `whenLoaded('relation')` resolves the relation against the model,
 * - `$this->whenCounted(...)` → `integer`, optional; any other `when*` wrapper → an
 *   unconstrained optional field,
 * - `$this->merge([...])` / `$this->mergeWhen(..., [...])` → the literal payload's keys inlined
 *   at the top level (optional for `mergeWhen`); a non-literal payload is skipped and flagged,
 * - anything else (method calls, ternaries, unknown fields) → the key is kept with an
 *   unconstrained schema — dropping a response property would be silently wrong.
 *
 * Returns null when the bounded case does not hold (no override, a dynamic body, an unreadable
 * key or spread); callers decide the fallback and the generation-log note — the reader is pure
 * and registry-free, so the `resource.fields-undeclared` lint rule can consult it without side
 * effects. Results are memoised per class for the scoped lifetime.
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
     * Whether the resource overrides `toArray()` outside the framework — the discriminator
     * between the passthrough base case (no override; the wrapped model is the only source)
     * and a dynamic body the reader had to refuse.
     *
     * @param class-string<JsonResource> $resourceClass
     */
    public function overridesToArray(string $resourceClass): bool
    {
        $declaringClass = new ReflectionMethod($resourceClass, 'toArray')->getDeclaringClass()->getName();

        return !str_starts_with($declaringClass, 'Illuminate\\');
    }

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
        $fields = $this->fieldsFromArrayNode($literal, optional: false, modelClass: $modelClass, unreadableMergePayload: $hasUnreadableMergePayload);

        if ($fields === null) {
            return null;
        }

        return new InferredToArrayFields($fields, $hasUnreadableMergePayload);
    }

    // region Literal walking

    /**
     * Walks the keyed entries of the literal into fields. An unkeyed entry is only meaningful
     * as a `merge()` / `mergeWhen()` spread of further keyed entries; any other unkeyed entry,
     * a spread, or a non-literal key makes the structure unknowable — the whole literal is
     * refused (null) rather than partially documented under guessed keys.
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
     * The fields a `$this->merge([...])` / `$this->mergeWhen(..., [...])` entry inlines at the
     * top level, or an empty list when the payload is not a literal array (the skip is flagged —
     * those keys exist at runtime but cannot be documented). Returns null when the entry is not
     * a merge call at all: a plain unkeyed entry makes the whole literal unknowable.
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
     * Resolves the canonical conditional-field idioms — `$this->when()`, `$this->whenLoaded()`,
     * `$this->whenCounted()` — and treats any other `when`-prefixed resource method
     * (`whenHas`, `whenNotNull`, `whenPivotLoaded`, …) as an unconstrained conditional field:
     * its presence is runtime-decided, so claiming it as required would over-promise.
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
            // The condition argument is never analysed (the abort_if rule); only the value is.
            return $this->resolveValue($name, $arguments[1]->value, optional: true, modelClass: $modelClass);
        }

        if ($methodName === self::WHEN_LOADED) {
            // A two-argument whenLoaded supplies the value via a callback or expression.
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

    /**
     * A bare `whenLoaded('relation')` value: the relation name resolves against the wrapped
     * model's metadata — typically a `@property-read` relation annotation yielding a `$ref`.
     *
     * @param null|class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function modelPropertyFromRelationArgument(string $name, ?Arg $argument, ?string $modelClass): ?InferredResourceField
    {
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

    /**
     * A nested-resource value: `new X(...)`, `X::make(...)`, or `X::collection(...)` where `X`
     * is a concrete `JsonResource` subclass. A conditional wrapper inside the single argument
     * (`new X($this->whenLoaded('relation'))`) marks the field optional.
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
     * A `$this->field` or `$this->resource->field` reference, resolved against the wrapped
     * model's metadata. An unknown model or field keeps the key as an unconstrained property.
     *
     * @param null|class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveModelProperty(string $name, Expr $value, bool $optional, ?string $modelClass): ?InferredResourceField
    {
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

    // endregion

    // region Node shapes

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
     * The model field name of a `$this->field` or `$this->resource->field` fetch, or null.
     * `$this->resource` itself is the whole model object — not a single resolvable field.
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

    /**
     * Whether the receiver is `$this` or `$this->resource` — the two spellings of "the wrapped
     * model" inside a resource (`JsonResource` forwards unknown property reads to `resource`).
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

    private function unconstrained(string $name, bool $optional): InferredResourceField
    {
        return InferredResourceField::ofUnconstrained(
            $name,
            required: !$optional,
            property: new OA\Property(['property' => $name]),
        );
    }

    // endregion
}
