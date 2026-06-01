<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function sprintf;

/**
 * Reports schema property wire-names that do not follow the configured naming convention.
 *
 * The expected casing is injected via {@see IdentifierCase} and defaults to
 * {@see IdentifierCase::Camel} (e.g. `createdAt`), which matches the house style used across all
 * ApiResource FIELD_* constants in this codebase. Recursion into nested objects is handled by the
 * walker — this rule only checks the single node passed in.
 */
#[Scoped]
final readonly class FieldNameNamingInconsistent extends AbstractNamingRule implements FieldRuleVisitor
{
    public function __construct(
        #[Config('openapi.lint.style.property_name_case', 'camel')]
        IdentifierCase|string $case = IdentifierCase::Camel,
    ) {
        parent::__construct($case);
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if ($this->conforms($field->name)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Field name "%s" does not follow the %s naming convention',
                $field->name,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('field names'),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'field.name-naming-inconsistent';
    }

    #[Override]
    public function description(): string
    {
        return "Field name doesn't follow the project's property_name_case convention.";
    }
}
