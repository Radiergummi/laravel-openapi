<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function sprintf;
use function trim;

/**
 * Reports schema properties that have no description.
 */
final class FieldDescriptionMissing implements Rule, FieldRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if ($field->description !== null && trim($field->description) !== '') {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf('Schema property "%s" has no description', $field->name),
            fixHint: 'Add a description to the schema property explaining its meaning and constraints.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'field.description-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Underspecified;
    }

    #[Override]
    public function description(): string
    {
        return 'Schema property has no description.';
    }
}
