<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Attributes\RawSchema;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;

use function class_exists;
use function implode;
use function sprintf;

/**
 * Flags a payload/return class that carries a class-level `#[RawSchema]` alongside property-level
 * field attributes (`#[RequestField]`, `#[ResponseField]`, …). The raw schema replaces the
 * inferred body wholesale, so the field attributes never contribute and are dead.
 */
final class SchemaClassAttributeConflictsWithFieldAttributes implements OperationRuleVisitor, Rule
{
    public const string ID = 'schema.class-attribute-conflicts-with-field-attributes';

    /** @var array<class-string, true> */
    private array $seen = [];

    public function __construct(
        private readonly PayloadParameterScanner $scanner,
    ) {}

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function description(): string
    {
        return 'A class carries a class-level #[RawSchema] together with field-level attributes '
            . '(#[RequestField]/#[ResponseField]); the raw schema replaces the inferred body '
            . 'wholesale, so the field attributes have no effect.';
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $method = $operation->descriptor?->method;

        if ($method === null) {
            return;
        }

        $candidates = $this->scanner->candidates($method);

        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && !$returnType->isBuiltin()) {
            /** @var class-string $returnClass */
            $returnClass = $returnType->getName();
            $candidates[] = $returnClass;
        }

        foreach ($candidates as $className) {
            yield from $this->checkClass($className);
        }
    }

    /**
     * @param class-string $className
     *
     * @return iterable<Finding>
     */
    private function checkClass(string $className): iterable
    {
        if (isset($this->seen[$className]) || !class_exists($className)) {
            return;
        }

        $this->seen[$className] = true;

        $reflection = new ReflectionClass($className);

        if ($reflection->getAttributes(RawSchema::class) === []) {
            return;
        }

        $dead = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(
                FieldAttribute::class,
                ReflectionAttribute::IS_INSTANCEOF,
            );

            foreach ($attributes as $attribute) {
                $shortName = new ReflectionClass($attribute->getName())->getShortName();
                $dead[] = sprintf('$%s (#[%s])', $property->getName(), $shortName);
            }
        }

        if ($dead === []) {
            return;
        }

        yield new Finding(
            ruleId: self::ID,
            level: $this->level(),
            message: sprintf(
                '#[RawSchema] on %s replaces the inferred body, so these field attributes have '
                . 'no effect: %s.',
                $className,
                implode(', ', $dead),
            ),
            fixHint: 'The field attributes are no-ops under the class-level #[RawSchema]; remove '
            . 'them, or drop #[RawSchema] if the field attributes are the intended source.',
            context: [Finding::CONTEXT_SOURCE_CLASS => $className],
        );
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }
}
