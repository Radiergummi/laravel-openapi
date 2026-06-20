<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function sprintf;

/**
 * Reports `#[IgnoreLint]` suppressions that carry no `reason` argument.
 */
final class MetaNoSuppressionReason implements Rule, ApiRuleVisitor
{
    public string $id = 'meta.no-suppression-reason';
    public Severity $severity = Severity::Inconsistent;
    public string $description = '#[IgnoreLint] has no reason parameter.';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        foreach ($context->suppressions as $directive) {
            if ($directive->reason !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    "Suppression of '%s' has no reason — change to #[IgnoreLint('%s', reason: '…')]",
                    $directive->ruleId,
                    $directive->ruleId,
                ),
                location: new FindingLocation(file: $directive->file, line: $directive->line),
                fixHint: sprintf(
                    "Replace with #[IgnoreLint('%s', reason: 'why this rule is silenced here')]",
                    $directive->ruleId,
                ),
            );
        }
    }



}
