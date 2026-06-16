<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\SchemaFromArrayDefinition;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\SchemaDefinitionFromLiteral;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

use function array_key_exists;
use function class_exists;
use function is_a;
use function is_int;
use function is_string;
use function method_exists;

/**
 * Reads response fields from a Fractal transformer's `transform()` array literal.
 *
 * Handles the bounded case of a single straight-line `return [...]` ({@see SingleReturnArrayLiteralFinder}).
 * Values are resolved best-effort: PHP casts to JSON type, `$model->field` via Eloquent metadata,
 * literal scalars/arrays via {@see SchemaDefinitionFromLiteral}, anything else as unconstrained.
 * Returns null when the bounded case does not hold; callers decide the fallback.
 *
 * @internal
 */
#[Scoped]
final class TransformerTransformReader
{
    private const string TRANSFORM_METHOD = 'transform';

    /**
     * Referenced by FQCN string so the plugin never depends on `league/fractal` directly.
     */
    private const string TRANSFORMER_ABSTRACT_CLASS = 'League\\Fractal\\TransformerAbstract';

    /**
     * @var array<class-string, null|list<InferredTransformerField>>
     */
    private array $cache = [];

    public function __construct(
        private readonly SingleReturnArrayLiteralFinder $returnLiteralFinder,
        private readonly EloquentModelToSchema $modelToSchema,
    ) {}

    /**
     * Whether the class extends `TransformerAbstract` (the signal callers use before treating an attribute-free class as a transformer).
     */
    public function isTransformerSubclass(string $class): bool
    {
        return is_a($class, self::TRANSFORMER_ABSTRACT_CLASS, allow_string: true);
    }

    /**
     * @param class-string $transformerClass
     *
     * @return null|list<InferredTransformerField>
     *
     * @throws ReflectionException
     */
    public function read(string $transformerClass): ?array
    {
        if (array_key_exists($transformerClass, $this->cache)) {
            return $this->cache[$transformerClass];
        }

        return $this->cache[$transformerClass] = $this->readFields($transformerClass);
    }

    /**
     * @param class-string $transformerClass
     *
     * @return null|list<InferredTransformerField>
     *
     * @throws ReflectionException
     */
    private function readFields(string $transformerClass): ?array
    {
        if (!$this->declaresTransform($transformerClass)) {
            return null;
        }

        $method = new ReflectionMethod($transformerClass, self::TRANSFORM_METHOD);
        $literal = $this->returnLiteralFinder->find($method);

        if ($literal === null) {
            return null;
        }

        [$modelClass, $parameterName] = $this->modelParameter($method);

        $fields = [];

        foreach ($literal->items as $item) {
            // A spread or an unkeyed entry makes the structure unknowable, so the whole literal
            // is refused rather than partially documented under guessed keys.
            if ($item->unpack || $item->key === null) {
                return null;
            }

            try {
                $key = AstLiteralEvaluator::evaluate($item->key);
            } catch (NonLiteralValueException) {
                return null;
            }

            if (!is_string($key) && !is_int($key)) {
                return null;
            }

            $fields[] = $this->resolveValue((string) $key, $item->value, $modelClass, $parameterName);
        }

        return $fields;
    }

    /**
     * Whether the class declares a concrete `transform()` method.
     *
     * @param class-string $transformerClass
     *
     * @throws ReflectionException
     */
    public function declaresTransform(string $transformerClass): bool
    {
        return method_exists($transformerClass, self::TRANSFORM_METHOD)
            && !new ReflectionMethod($transformerClass, self::TRANSFORM_METHOD)->isAbstract();
    }

    // region Value resolution

    /**
     * The first parameter when its declared type is a concrete Eloquent model, used to resolve model fetches.
     *
     * @return array{null|class-string<Model>, null|string}
     */
    private function modelParameter(ReflectionMethod $method): array
    {
        $parameter = $method->getParameters()[0] ?? null;
        $type = $parameter?->getType();

        if ($parameter === null || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return [null, null];
        }

        $typeName = $type->getName();

        if (!class_exists($typeName) || !is_a($typeName, Model::class, allow_string: true)) {
            return [null, null];
        }

        /** @var class-string<Model> $typeName */
        return [$typeName, $parameter->getName()];
    }

    /**
     * @param null|class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveValue(
        string $name,
        Expr $value,
        ?string $modelClass,
        ?string $parameterName,
    ): InferredTransformerField {
        $castType = $this->castType($value);

        if ($castType !== null) {
            return new InferredTransformerField($name, new OA\Property([
                'property' => $name,
                'type' => $castType,
                // swagger-php's validation requires items whenever type is array; an (array)
                // cast guarantees an array of unknown items, so the honest claim is items: {}.
                ...$castType === 'array' ? ['items' => new OA\Items([])] : [],
            ]));
        }

        $modelProperty = $this->resolveModelProperty($name, $value, $modelClass, $parameterName);

        if ($modelProperty !== null) {
            return $modelProperty;
        }

        $definition = SchemaDefinitionFromLiteral::fromValue($value);

        if ($definition !== []) {
            return new InferredTransformerField(
                $name,
                SchemaFromArrayDefinition::buildProperty($name, $definition),
                unconstrainedPaths: $this->unreadableNestedPaths($definition, $name),
            );
        }

        return $this->unconstrained($name);
    }

    /**
     * The JSON type a cast expression guarantees at runtime, regardless of what it wraps.
     */
    private function castType(Expr $value): ?string
    {
        return match (true) {
            $value instanceof Cast\Int_ => 'integer',
            $value instanceof Cast\Double => 'number',
            $value instanceof Cast\String_ => 'string',
            $value instanceof Cast\Bool_ => 'boolean',
            $value instanceof Cast\Array_ => 'array',
            default => null,
        };
    }

    /**
     * Resolves a `$model->field` fetch against Eloquent model metadata; returns unconstrained for unknown fields.
     *
     * @param null|class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function resolveModelProperty(
        string $name,
        Expr $value,
        ?string $modelClass,
        ?string $parameterName,
    ): ?InferredTransformerField {
        if ($modelClass === null || $parameterName === null) {
            return null;
        }

        if (
            !$value instanceof PropertyFetch
            || !$value->name instanceof Identifier
            || !$value->var instanceof Variable
            || $value->var->name !== $parameterName
        ) {
            return null;
        }

        $property = $this->modelToSchema->propertyFor($modelClass, $value->name->toString());

        if ($property === null) {
            return $this->unconstrained($name);
        }

        $property->property = $name;

        return new InferredTransformerField($name, $property);
    }

    private function unconstrained(string $name): InferredTransformerField
    {
        return new InferredTransformerField($name, new OA\Property(['property' => $name]), unconstrainedPaths: [$name]);
    }

    /**
     * Key paths of unconstrained (empty) nested definitions, for surfacing in the generation notice.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    private function unreadableNestedPaths(array $definition, string $path): array
    {
        $paths = [];

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $definition['properties'] ?? [];

        foreach ($properties as $key => $nested) {
            $nestedPath = "{$path}.{$key}";

            $paths = $nested === []
                ? [...$paths, $nestedPath]
                : [...$paths, ...$this->unreadableNestedPaths($nested, $nestedPath)];
        }

        /** @var array<string, mixed> $items */
        $items = $definition['items'] ?? [];

        if ($items === []) {
            return $paths;
        }

        return [...$paths, ...$this->unreadableNestedPaths($items, "{$path}[]")];
    }

    // endregion
}
