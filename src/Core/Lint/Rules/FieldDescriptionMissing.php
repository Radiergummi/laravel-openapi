<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\FieldRule as FieldRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;

use function sprintf;
use function trim;

/**
 * Reports schema properties (fields) that have no description.
 *
 * Every property in a schema should have a description so that API consumers understand the
 * semantics and constraints of each field.
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
            level: $this->level(),
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
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Schema property has no description.';
    }
}
