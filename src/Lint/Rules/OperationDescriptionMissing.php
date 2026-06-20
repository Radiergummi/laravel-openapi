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

/**
 * Reports operations that have a summary but no accompanying description.
 *
 * Operations missing both summary and description are covered by `summary.missing` instead.
 */
final class OperationDescriptionMissing implements Rule, OperationRuleVisitor
{
    public string $id = 'operation.description-missing';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'Operation has no description (beyond the summary).';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->summary === null) {
            return;
        }

        if ($operation->description === null) {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'Operation %s %s has no description',
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Add a description to complement the summary and provide more detail to API consumers.',
            );
        }
    }



}
