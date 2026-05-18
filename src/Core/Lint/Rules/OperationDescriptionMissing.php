<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Override;

use function sprintf;

/**
 * Reports operations that have a summary but no description.
 *
 * When an operation provides a summary, consumers expect a more detailed
 * description to accompany it. Operations missing both summary and description
 * are covered by the `summary.missing` rule instead.
 */
final class OperationDescriptionMissing implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        // Operations missing both summary and description are covered by the
        // summary.missing rule — only flag when a summary exists but no
        // accompanying description does.
        if ($operation->summary === null) {
            return;
        }

        if ($operation->description === null) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s has no description',
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Add a description to complement the summary and provide more detail to API consumers.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'operation.description-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no description (beyond the summary).';
    }
}
