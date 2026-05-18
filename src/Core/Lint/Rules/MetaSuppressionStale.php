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
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\PostWalkRule;
use Radiergummi\OpenApi\Core\Lint\SuppressionDirective;

use function sprintf;

/**
 * Reports suppression directives that did not actually suppress any finding.
 * Stale suppressions add noise and may hide the fact that a previous issue was
 * resolved.
 *
 * This rule implements {@see PostWalkRule} because it needs the complete set
 * of findings from all other rules before it can determine which suppressions
 * are stale. It is intentionally excluded from the RULES array in
 * {@see \Radiergummi\OpenApi\Core\Registry\CoreRegistration}.
 */
final class MetaSuppressionStale implements Rule, PostWalkRule
{
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
                level: $this->level(),
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
        foreach ($allFindings as $finding) {
            if ($directive->suppresses($finding)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonical rule ID.
     *
     * Exposed as a constant because this rule is not part of the registered
     * rule set, so callers assembling the known-rule-ID list (the lint command,
     * or tests building a {@see \Radiergummi\OpenApi\Core\Lint\TreeIndex}
     * directly) must reference it without instantiating the rule.
     */
    public const string ID = 'meta.suppression-stale';

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return '#[IgnoreLint] directive did not suppress any finding.';
    }
}
