<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;

use function is_array;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Reports component schemas that carry neither `example` nor `examples`. Enum schemas are exempt
 * as their allowed values already document the expected content.
 */
final class SchemaExampleMissing implements Rule, ComponentSchemaRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkComponentSchema(
        ComponentSchemaNode $componentSchema,
        LintContext $context,
    ): iterable {
        $schema = $componentSchema->raw;

        if ($schema === null) {
            return;
        }

        $hasExample = is_defined($schema->example) && $schema->example !== null;

        $hasExamples
            = is_defined($schema->examples)
            && is_array($schema->examples)
            && $schema->examples !== [];

        $hasEnum
            = is_defined($schema->enum)
            && is_array($schema->enum)
            && $schema->enum !== [];

        if ($hasExample || $hasExamples || $hasEnum) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf('Schema "%s" has no example value', $componentSchema->name),
            location: new FindingLocation(
                jsonPointer: ComponentReference::pointer($componentSchema->name),
            ),
            fixHint: 'Add an "example" or "examples" property to the schema to improve documentation.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'schema.example-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Improvable;
    }

    #[Override]
    public function description(): string
    {
        return 'Schema property has no example value.';
    }
}
