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
use function trim;

/**
 * Reports operations that have no summary defined.
 */
final class SummaryMissing implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->summary === null || trim($operation->summary) === '') {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s has no summary',
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Add a PHPDoc summary line to the controller method.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'summary.missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no summary.';
    }
}
