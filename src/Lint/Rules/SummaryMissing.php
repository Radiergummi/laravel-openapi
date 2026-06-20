<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function sprintf;
use function trim;

/**
 * Reports operations that have no summary defined.
 */
final class SummaryMissing implements Rule, OperationRuleVisitor
{
    public string $id = 'summary.missing';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'Operation has no summary.';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->summary === null || trim($operation->summary) === '') {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'Operation %s %s has no summary',
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Add a PHPDoc summary line to the controller method.',
            );
        }
    }



}
