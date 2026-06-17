<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;
use Traversable;

use function array_map;
use function array_values;
use function in_array;
use function iterator_to_array;
use function max;
use function min;

final class RuleRegistry
{
    public const string EXEMPT_RULE_ID = 'spec.invalid';

    /** @var list<Rule> */
    private readonly array $rules;

    /**
     * @param iterable<Rule>     $rules
     * @param array<string, int> $severityOverrides Per-rule severity remap: rule-id => level int.
     *                                              `spec.invalid` is exempt and cannot be remapped.
     */
    public function __construct(
        iterable $rules,
        private readonly array $severityOverrides = [],
    ) {
        // Materialize eagerly: the registry is queried multiple times and a generator would not survive.
        $this->rules = $rules instanceof Traversable
            ? iterator_to_array($rules, false)
            : array_values($rules);
    }

    /** @return list<Rule> */
    public function all(): array
    {
        return $this->rules;
    }

    /** @return list<PreBuildRule> */
    public function preBuildRules(): array
    {
        return array_values(
            array_filter(
                $this->rules,
                static fn(Rule $rule): bool => $rule instanceof PreBuildRule,
            ),
        );
    }

    /**
     * @param list<string> $only
     * @param list<string> $skip
     *
     * @return list<Rule>
     */
    public function forLevel(
        int $level,
        array $only = [],
        array $skip = [],
    ): array {
        $kept = [];

        foreach ($this->rules as $rule) {
            if ($this->effectiveLevel($rule)->value > $level) {
                continue;
            }

            if ($only !== [] && !in_array($rule->id(), $only, true)) {
                continue;
            }

            if (in_array($rule->id(), $skip, true)) {
                continue;
            }

            $kept[] = $rule;
        }

        return $kept;
    }

    private function effectiveLevel(Rule $rule): Severity
    {
        return $this->effectiveLevelFor($rule->id(), $rule->severity());
    }

    /**
     * Resolve a rule's effective severity, applying any config override.
     *
     * `spec.invalid` is always exempt from remapping. Out-of-range override ints are clamped into
     * the severity range before resolution, so a stray value can never escape the closed enum.
     */
    public function effectiveLevelFor(string $ruleId, Severity $fallback): Severity
    {
        if ($ruleId === self::EXEMPT_RULE_ID) {
            return $fallback;
        }

        $override = $this->severityOverrides[$ruleId] ?? null;

        if ($override === null) {
            return $fallback;
        }

        $clamped = max(Severity::Broken->value, min(Severity::Improvable->value, $override));

        return Severity::tryFrom($clamped) ?? $fallback;
    }

    /** @return list<string> */
    public function knownIds(): array
    {
        $ids = [];

        foreach ($this->rules as $rule) {
            $ids[] = $rule->id();
        }

        return $ids;
    }

    /** Returns the highest effective level (by severity value) among all registered rules. */
    public function maxLevel(): int
    {
        $max = 0;

        foreach ($this->rules as $rule) {
            $max = max($max, $this->effectiveLevel($rule)->value);
        }

        return $max;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function applyOverrides(array $findings): array
    {
        if ($this->severityOverrides === []) {
            return $findings;
        }

        return array_map(function (Finding $finding): Finding {
            $effective = $this->effectiveLevelFor($finding->ruleId, $finding->severity);

            return $effective === $finding->severity
                ? $finding
                : $finding->withSeverity($effective);
        }, $findings);
    }
}
