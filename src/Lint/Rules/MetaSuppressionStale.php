<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Lint\Visitors\PostWalkRule;

use function sprintf;

/**
 * Reports suppression directives that did not actually suppress any finding. Stale suppressions
 * add noise and may hide that a previous issue was resolved.
 *
 * Implements {@see PostWalkRule} because it needs the complete set of findings from all other
 * rules before it can determine which suppressions are stale. Not registered in the default
 * rule set; callers reference the ID via {@see self::ID}.
 */
final class MetaSuppressionStale implements Rule, PostWalkRule
{
    /** Not in the registered rule set, so callers reference the ID via this constant. */
    public const string ID = 'meta.suppression-stale';

    /**
     * @param list<Finding> $walkFindings
     *
     * @return iterable<Finding>
     */
    #[Override]
    public function check(LintContext $context, array $walkFindings): iterable
    {
        return $this->findStaleSuppressions($context, $walkFindings);
    }

    /**
     * @param list<Finding> $allFindings
     *
     * @return iterable<Finding>
     */
    private function findStaleSuppressions(LintContext $context, array $allFindings): iterable
    {
        foreach ($context->suppressions as $directive) {
            if ($this->directiveMatchesAnyFinding($directive, $allFindings)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: sprintf(
                    'Suppression for %s did not suppress any finding — it may be stale',
                    $directive->ruleId,
                ),
                location: new FindingLocation(
                    file: $directive->file,
                    line: $directive->line,
                ),
                fixHint: 'Remove the suppression directive if the underlying issue has been resolved.',
            );
        }
    }

    /**
     * @param list<Finding> $allFindings
     */
    private function directiveMatchesAnyFinding(
        SuppressionDirective $directive,
        array $allFindings,
    ): bool {
        return array_any(
            $allFindings,
            fn(Finding $finding): bool => $directive->suppresses($finding),
        );
    }

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Inconsistent;
    }

    #[Override]
    public function description(): string
    {
        return '#[IgnoreLint] directive did not suppress any finding.';
    }
}
