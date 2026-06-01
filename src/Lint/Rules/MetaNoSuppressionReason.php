<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function sprintf;

final class MetaNoSuppressionReason implements Rule, ApiRuleVisitor
{
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
                ruleId: $this->id(),
                level: $this->level(),
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

    #[Override]
    public function id(): string
    {
        return 'meta.no-suppression-reason';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return '#[IgnoreLint] has no reason parameter.';
    }
}
