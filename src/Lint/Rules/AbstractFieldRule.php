<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

use function assert;
use function class_basename;

/**
 * Base class for rules that inspect scoped field attributes (`#[RequestField]`, `#[ResponseField]`,
 * `#[PathParam]`, `#[QueryParam]`) on properties of payload classes injected
 * into controller methods.
 *
 * Handles the full traversal pipeline:
 *   operation → descriptor → method params → payload classes → properties → FieldAttribute
 *
 * Subclasses implement a single `inspectField()` hook that receives the resolved FieldAttribute
 * instance, property, and operation context.
 *
 * Uses {@see PayloadParameterScanner} so Data classes injected through Domain Actions
 * (the standard write-endpoint pattern) are also inspected.
 */
abstract class AbstractFieldRule implements Rule, OperationRuleVisitor
{
    public function __construct(
        private readonly PayloadParameterScanner $scanner,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook) {
            return [];
        }

        if ($operation->descriptor?->method === null) {
            return [];
        }

        foreach ($this->scanner->candidates($operation->descriptor->method) as $className) {
            if (!$context->isPayloadClass($className)) {
                continue;
            }

            yield from $this->checkDataClass(
                new ReflectionClass($className),
                $operation,
            );
        }
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return iterable<Finding>
     */
    private function checkDataClass(ReflectionClass $class, OperationNode $operation): iterable
    {
        foreach ($class->getProperties() as $property) {
            yield from $this->checkProperty($property, $operation);
        }
    }

    /** @return iterable<Finding> */
    private function checkProperty(ReflectionProperty $property, OperationNode $operation): iterable
    {
        $attributes = $property->getAttributes(FieldAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            return;
        }

        $field = $attributes[0]->newInstance();
        assert($field instanceof FieldAttribute);

        // Stamp the source class and property so a property-scoped
        // #[IgnoreLint] directive can match this finding structurally.
        $context = [
            Finding::CONTEXT_SOURCE_CLASS => $property->getDeclaringClass()->getName(),
            Finding::CONTEXT_SOURCE_MEMBER => $property->getName(),
        ];

        foreach ($this->inspectField($field, $property, $operation) as $finding) {
            yield $finding->withMergedContext($context);
        }
    }

    /**
     * Inspect a single scoped field attribute on a property.
     *
     * @return iterable<Finding>
     */
    abstract protected function inspectField(
        FieldAttribute $field,
        ReflectionProperty $property,
        OperationNode $operation,
    ): iterable;

    /**
     * The short class name of a field attribute, e.g. "RequestField".
     */
    protected function attributeName(FieldAttribute $field): string
    {
        return class_basename($field);
    }
}
