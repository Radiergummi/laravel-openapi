<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Override;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

use function assert;
use function class_basename;

/**
 * Base class for rules that inspect scoped field attributes (`#[RequestField]`, `#[ResponseField]`,
 * `#[PathParam]`, `#[QueryParam]`) on properties of payload classes injected into controller methods.
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
     *
     * @throws ReflectionException
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

        // Use PayloadParameterScanner so Action-indirected Data classes are
        // reached (e.g. CreateProjectAction whose constructor takes CreateProjectData).
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

    /** @return iterable<Finding> */
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
     * The short class name of a field attribute, e.g. "RequestField".
     */
    protected function attributeName(FieldAttribute $field): string
    {
        return class_basename($field);
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
}
