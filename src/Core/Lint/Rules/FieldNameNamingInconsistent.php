<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\FieldRule as FieldRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;

use function sprintf;

/**
 * Reports schema property wire-names that do not follow the configured naming
 * convention.
 *
 * The expected casing is injected via {@see IdentifierCase} and defaults to
 * {@see IdentifierCase::Camel} (e.g. `createdAt`), which matches the house
 * style used across all ApiResource FIELD_* constants in this codebase.
 * Recursion into nested objects is handled by the walker — this rule only
 * checks the single node passed in.
 */
final readonly class FieldNameNamingInconsistent extends AbstractNamingRule implements FieldRuleVisitor
{
    public function __construct(IdentifierCase $case = IdentifierCase::Camel)
    {
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
