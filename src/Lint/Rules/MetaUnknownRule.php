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

use function in_array;
use function sprintf;

final class MetaUnknownRule implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $knownRuleIds = $context->index->knownRuleIds;

        foreach ($context->suppressions as $directive) {
            if (in_array($directive->ruleId, $knownRuleIds, true)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: sprintf('Suppression references unknown rule "%s"', $directive->ruleId),
                location: new FindingLocation(file: $directive->file, line: $directive->line),
                fixHint: 'Check spelling or remove the obsolete suppression',
                context: ['unknown_id' => $directive->ruleId],
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'meta.unknown-rule';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Inconsistent;
    }

    #[Override]
    public function description(): string
    {
        return '#[IgnoreLint] references a rule ID not in the registry.';
    }
}
