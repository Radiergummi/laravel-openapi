<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;
use Traversable;

use function array_map;
use function array_values;
use function in_array;
use function iterator_to_array;
use function max;

final class RuleRegistry
{
    public const string EXEMPT_RULE_ID = 'spec.invalid';

    /** @var list<Rule> */
    private readonly array $rules;

    /**
     * @param iterable<Rule>     $rules
     * @param array<string, int> $severityOverrides Per-rule severity remap: rule-id => level.
     *                                              `spec.invalid` is exempt and cannot be remapped.
     */
    public function __construct(
        iterable $rules,
        private readonly array $severityOverrides = [],
    ) {
        // Materialize eagerly: the registry is queried multiple times (forLevel/knownIds/maxLevel),
        // which a one-shot generator could not survive.
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
            if ($this->effectiveLevel($rule) > $level) {
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

    /**
     * Returns the effective level for a rule instance, applying any configured override.
     * `spec.invalid` is always exempt from remapping.
     */
    private function effectiveLevel(Rule $rule): int
    {
        return $this->effectiveLevelFor($rule->id(), $rule->level());
    }

    /**
     * Returns the effective level for a rule ID, applying any configured override.
     * `spec.invalid` is always exempt from remapping.
     */
    public function effectiveLevelFor(string $ruleId, int $fallback): int
    {
        if ($ruleId === self::EXEMPT_RULE_ID) {
            return $fallback;
        }

        return $this->severityOverrides[$ruleId] ?? $fallback;
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

    /** Returns the highest effective level among all registered rules. */
    public function maxLevel(): int
    {
        $max = 0;

        foreach ($this->rules as $rule) {
            $max = max($max, $this->effectiveLevel($rule));
        }

        return $max;
    }

    /**
     * Returns a copy of each finding with its level remapped per the configured
     * severity overrides. Findings without an override are returned unchanged.
     *
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
            $effective = $this->effectiveLevelFor($finding->ruleId, $finding->level);

            return $effective === $finding->level
                ? $finding
                : $finding->withLevel($effective);
        }, $findings);
    }
}
