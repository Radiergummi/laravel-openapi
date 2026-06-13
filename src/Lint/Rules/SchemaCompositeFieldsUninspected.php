<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Tree\SchemaAccessor;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function sprintf;

/**
 * Reports schema properties whose `oneOf` / `anyOf` is a union of two or more genuine alternatives,
 * which field-level rules cannot descend into. Without this, such a property produces no field
 * findings at all, leaving the coverage gap invisible.
 */
final class SchemaCompositeFieldsUninspected implements Rule, FieldRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if ($field->raw === null) {
            return;
        }

        if (!SchemaAccessor::classifyComposition($field->raw)['uninspectedComposite']) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Field "%s" is a oneOf/anyOf union of multiple alternatives; its branches are not inspected by field-level rules',
                $field->name,
            ),
            fixHint: 'Document each alternative by hand, or restructure the schema so the property resolves to a single concrete shape.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'schema.composite-fields-uninspected';
    }

    #[Override]
    public function level(): int
    {
        // A coverage gap, not invalid documentation.
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Schema property is a oneOf/anyOf of multiple alternatives whose fields are not inspected by field-level rules.';
    }
}
