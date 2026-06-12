<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Types;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;
use Reflector;

use function class_exists;
use function count;
use function get_object_vars;
use function in_array;
use function strtolower;

/**
 * Resolves a phpstan/phpdoc-parser {@see TypeNode} into a swagger-php {@see OA\Schema}, handling
 * the *structural* shapes that symfony/type-info's {@see \Symfony\Component\TypeInfo\Type} model
 * cannot represent — array shapes (`array{foo: string}`), list/array-of forms, and string-keyed
 * maps — recursively.
 *
 * Leaf class identifiers are not resolved to schemas here: the caller supplies a strategy
 * (`callable(string $fqcn): ?OA\Schema`) so consumer-specific policy (e.g. a Model `$ref` vs. an
 * inline object schema) stays out of this resolver. This is the {@see TypeNode}-keyed companion to
 * {@see \Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType} (which is keyed on resolved
 * symfony types). Pure Tier-0: reflection/PHPDoc only, no method-body parsing.
 *
 * @internal
 */
#[Scoped]
final readonly class TypeNodeToSchema
{
    /** Generic base identifiers that denote an array/list rather than a class. */
    private const array ARRAY_GENERICS = ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable'];

    public function __construct(
        private TypeNodeResolver $typeNodeResolver,
    ) {}

    public static function create(): self
    {
        return new self(TypeNodeResolver::create());
    }

    /**
     * Resolves a type node to a schema, or null when the node is a shape this resolver does not
     * model (so the caller falls through to its own fallback). Nullability is applied internally —
     * `?T` / `T|null` is wrapped via the OAS 3.1 idiom ({@see NullableSchema}) — so callers must
     * not wrap again.
     *
     * @param callable(string): ?OA\Schema $classSchema resolves a leaf FQCN to a schema
     */
    public function resolve(TypeNode $node, Reflector $context, callable $classSchema): ?OA\Schema
    {
        if ($this->typeNodeResolver->isNullable($node)) {
            $inner = $this->typeNodeResolver->unwrapNullable($node);

            // unwrapNullable returns the node unchanged for a multi-class union (e.g. A|B|null);
            // that is not a simple nullable and is left to the caller's fallback.
            if ($inner === $node) {
                return null;
            }

            $schema = $this->resolve($inner, $context, $classSchema);

            return $schema === null ? null : NullableSchema::wrap($schema);
        }

        if ($node instanceof ArrayShapeNode) {
            return $this->objectFromShape($node, $context, $classSchema);
        }

        if ($node instanceof ArrayTypeNode) {
            return $this->listOf($this->resolve($node->type, $context, $classSchema));
        }

        if ($node instanceof GenericTypeNode) {
            return $this->fromGeneric($node, $context, $classSchema);
        }

        if ($node instanceof IdentifierTypeNode) {
            return $this->fromIdentifier($node, $context, $classSchema);
        }

        return null;
    }

    /**
     * @param callable(string): ?OA\Schema $classSchema
     */
    private function objectFromShape(ArrayShapeNode $node, Reflector $context, callable $classSchema): OA\Schema
    {
        $properties = [];
        $required = [];

        foreach ($node->items as $index => $item) {
            $name = $this->itemKey($item, $index);
            $value = $this->resolve($item->valueType, $context, $classSchema) ?? new OA\Schema([]);

            $properties[] = $this->propertyFromSchema($name, $value);

            if (! $item->optional) {
                $required[] = $name;
            }
        }

        $arguments = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $arguments['required'] = $required;
        }

        return new OA\Schema($arguments);
    }

    /**
     * @param callable(string): ?OA\Schema $classSchema
     */
    private function fromGeneric(GenericTypeNode $node, Reflector $context, callable $classSchema): ?OA\Schema
    {
        if (! in_array(strtolower($node->type->name), self::ARRAY_GENERICS, strict: true)) {
            return null;
        }

        // array<K, V>: a string key denotes a map (additionalProperties), an int key a list.
        if (count($node->genericTypes) === 2) {
            [$key, $value] = $node->genericTypes;
            $valueSchema = $this->resolve($value, $context, $classSchema) ?? new OA\Schema([]);

            if ($key instanceof IdentifierTypeNode && $this->isStringKey($key->name)) {
                return new OA\Schema(['type' => 'object', 'additionalProperties' => $valueSchema]);
            }

            return $this->listOf($valueSchema);
        }

        // list<V> / array<V>: single argument is the value type.
        if (count($node->genericTypes) === 1) {
            return $this->listOf($this->resolve($node->genericTypes[0], $context, $classSchema));
        }

        return null;
    }

    /**
     * @param callable(string): ?OA\Schema $classSchema
     */
    private function fromIdentifier(IdentifierTypeNode $node, Reflector $context, callable $classSchema): ?OA\Schema
    {
        $definition = $this->scalarDefinition($node->name);

        if ($definition !== null) {
            return new OA\Schema($definition);
        }

        $className = $this->typeNodeResolver->resolveClassName($node, $context);

        if ($className === null || ! class_exists($className)) {
            return null;
        }

        return $classSchema($className);
    }

    /**
     * Wraps an element schema as an array schema. `items` is always emitted (an empty
     * {@see OA\Items} when the element is unreadable) — swagger-php rejects `type: array`
     * without `items`.
     */
    private function listOf(?OA\Schema $element): OA\Schema
    {
        $items = new OA\Items([]);

        if ($element !== null) {
            $this->copyDefinedFields($element, $items);
        }

        return new OA\Schema(['type' => 'array', 'items' => $items]);
    }

    private function itemKey(ArrayShapeItemNode $item, int $index): string
    {
        return match (true) {
            $item->keyName instanceof IdentifierTypeNode  => $item->keyName->name,
            $item->keyName instanceof ConstExprStringNode  => $item->keyName->value,
            $item->keyName instanceof ConstExprIntegerNode => $item->keyName->value,
            $item->keyName === null                        => (string) $index,
            default                                        => (string) $item->keyName,
        };
    }

    private function isStringKey(string $keyword): bool
    {
        return in_array(strtolower($keyword), ['string', 'array-key', 'non-empty-string'], strict: true);
    }

    /**
     * @return null|array<string, string>
     */
    private function scalarDefinition(string $keyword): ?array
    {
        return match (strtolower($keyword)) {
            'int', 'integer'                   => ['type' => 'integer'],
            'float', 'double'                  => ['type' => 'number'],
            'string'                           => ['type' => 'string'],
            'bool', 'boolean', 'true', 'false' => ['type' => 'boolean'],
            default                            => null,
        };
    }

    private function propertyFromSchema(string $name, OA\Schema $schema): OA\Property
    {
        $property = new OA\Property(['property' => $name]);
        $this->copyDefinedFields($schema, $property);

        return $property;
    }

    /**
     * Copies every defined JSON-Schema field from a source schema onto a target schema subclass
     * (OA\Property / OA\Items share OA\Schema's field set). swagger-php internals (underscore
     * fields) and the component-key fields are skipped.
     */
    private function copyDefinedFields(OA\Schema $source, OA\Schema $target): void
    {
        foreach (get_object_vars($source) as $field => $value) {
            if (in_array($field, ['property', 'schema'], strict: true) || $field[0] === '_') {
                continue;
            }

            if ($value !== Generator::UNDEFINED) {
                $target->{$field} = $value;
            }
        }
    }
}
