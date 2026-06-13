<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function sprintf;

/**
 * Reports schema properties whose `oneOf` / `anyOf` is a union of two or more genuine alternatives,
 * which the tree builder does not descend into.
 *
 * Field-level rules (`field.description-missing`, `schema.enum-type-mismatch`, …) inspect a single
 * concrete schema per property. The standard nullable encoding (`oneOf: [<concrete>, {type: 'null'}]`)
 * is unwrapped to its concrete branch and inspected normally; a genuine multi-alternative union is
 * not — unioning the alternatives' field sets is out of scope. Without this rule, those properties
 * would simply produce no field findings, leaving the gap invisible. This makes it explicit so the
 * author can document the branches by hand or restructure the schema.
 */
final class SchemaCompositeFieldsUninspected implements Rule, FieldRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if (!$field->uninspectedCompositeBranches) {
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
        // Potential issue: the branches may hide undocumented fields the linter cannot reach. Not
        // invalid documentation (level 1) and more than a style nit (level 4) — it is a coverage gap.
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Schema property is a oneOf/anyOf of multiple alternatives whose fields are not inspected by field-level rules.';
    }
}
