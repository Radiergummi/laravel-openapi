<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use LogicException;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use ReflectionException;
use RuntimeException;

use function spl_object_id;

/**
 * Drives `openapi:lint --fix` / `--check`.
 *
 * Runs a normal lint, then for every finding whose owning rule is a {@see FixableRule} asks that
 * rule's {@see Fixer} for edits. All edits are handed to the {@see FixApplicator} as one batch (so
 * conflicts are resolved across rules and files together) and a finding counts as *fixed* only when
 * every edit it produced was actually applied — edits dropped by conflict resolution leave their
 * finding in the remaining set. There is no re-lint pass: fixers are trusted and golden-tested.
 *
 * @internal
 */
#[Scoped]
final readonly class FixRunner
{
    public function __construct(
        private LintRunner $linter,
        private RuleRegistry $registry,
        private FixApplicator $applicator,
    ) {}

    /**
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ReflectionException
     * @throws RuntimeException
     */
    public function run(LintOptions $options, bool $dryRun): FixRunResult
    {
        $lint = $this->linter->run($options);
        $fixers = $this->fixers();
        $context = new FixContext();

        /** @var list<array{finding: Finding, fixes: list<Fix>}> $planned */
        $planned = [];
        $allFixes = [];

        foreach ($lint->findings as $finding) {
            $fixes = [];
            $fixer = $fixers[$finding->ruleId] ?? null;

            if ($fixer !== null) {
                foreach ($fixer->fix($finding, $context) as $fix) {
                    $fixes[] = $fix;
                    $allFixes[] = $fix;
                }
            }

            $planned[] = ['finding' => $finding, 'fixes' => $fixes];
        }

        $fixResult = $this->applicator->apply($allFixes, $dryRun);

        $appliedIds = [];

        foreach ($fixResult->applied as $fix) {
            $appliedIds[spl_object_id($fix)] = true;
        }

        $remaining = [];

        foreach ($planned as $entry) {
            if (!$this->wasFixed($entry['fixes'], $appliedIds)) {
                $remaining[] = $entry['finding'];
            }
        }

        return new FixRunResult($fixResult, $remaining, $lint->level, $dryRun);
    }

    /**
     * A finding is fixed only when it produced at least one edit and all of its edits survived
     * conflict resolution.
     *
     * @param list<Fix>        $fixes
     * @param array<int, true> $appliedIds
     */
    private function wasFixed(array $fixes, array $appliedIds): bool
    {
        if ($fixes === []) {
            return false;
        }

        foreach ($fixes as $fix) {
            if (!isset($appliedIds[spl_object_id($fix)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, Fixer>
     */
    private function fixers(): array
    {
        $fixers = [];

        foreach ($this->registry->all() as $rule) {
            if ($rule instanceof FixableRule) {
                $fixers[$rule->id()] = $rule->fixer();
            }
        }

        return $fixers;
    }
}
