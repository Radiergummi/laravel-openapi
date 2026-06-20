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

use function array_keys;
use function spl_object_id;

/**
 * Runs a normal lint, collects edits from every {@see FixableRule}'s {@see Fixer}, and applies
 * them as a single batch via {@see FixApplicator}. A finding is fixed only when all its edits
 * survive conflict resolution; dropped edits leave their finding in the remaining set.
 * No re-lint pass: fixers are trusted and golden-tested.
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
        private WorkingTreeGuard $workingTreeGuard = new WorkingTreeGuard(),
    ) {}

    /**
     * @param bool $applyDestructive Apply {@see FixSafety::Destructive} fixes too (`--fix=dangerous`);
     *                               when false they are withheld and counted.
     * @param bool $allowDirty       Skip the clean-working-tree rail that otherwise gates destructive
     *                               writes (`--allow-dirty`).
     *
     * @throws BindingResolutionException
     * @throws DirtyWorkingTreeException  When destructive fixes would write to a dirty/non-git tree
     *                                    and `--allow-dirty` was not given.
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ReflectionException
     * @throws RuntimeException
     */
    public function run(
        LintOptions $options,
        bool $dryRun,
        bool $applyDestructive = false,
        bool $allowDirty = false,
    ): FixRunResult {
        $lint = $this->linter->run($options);
        $fixers = $this->fixers();
        $context = new FixContext();

        /** @var list<array{finding: Finding, fixes: list<Fix>}> $planned */
        $planned = [];
        $applicable = [];
        $withheldDestructiveCount = 0;

        foreach ($lint->findings as $finding) {
            $fixes = [];
            $fixer = $fixers[$finding->ruleId] ?? null;

            if ($fixer !== null) {
                foreach ($fixer->fix($finding, $context) as $fix) {
                    $fixes[] = $fix;

                    if ($fix->safety === FixSafety::Destructive && !$applyDestructive) {
                        $withheldDestructiveCount++;

                        continue;
                    }

                    $applicable[] = $fix;
                }
            }

            $planned[] = ['finding' => $finding, 'fixes' => $fixes];
        }

        // The clean-tree rail only guards destructive writes; a safe-only run never touches git.
        if (!$dryRun && $applyDestructive) {
            $this->workingTreeGuard->assertClean($this->destructiveTargetFiles($applicable), $allowDirty);
        }

        $fixResult = $this->applicator->apply($applicable, $dryRun);

        $appliedIds = [];

        foreach ($fixResult->applied as $fix) {
            $appliedIds[spl_object_id($fix)] = true;
        }

        $remaining = [];

        // A finding whose only fix was withheld stays here: the source is genuinely unchanged, so it
        // is both an unresolved gap and counted as withheld, never dropped from the remaining set.
        foreach ($planned as $entry) {
            if (!$this->wasFixed($entry['fixes'], $appliedIds)) {
                $remaining[] = $entry['finding'];
            }
        }

        return new FixRunResult($fixResult, $remaining, $lint->level, $dryRun, $withheldDestructiveCount);
    }

    /**
     * @param list<Fix> $applicable
     *
     * @return list<string>
     */
    private function destructiveTargetFiles(array $applicable): array
    {
        $files = [];

        foreach ($applicable as $fix) {
            if ($fix->safety === FixSafety::Destructive) {
                $files[$fix->file] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * @return array<string, Fixer>
     */
    private function fixers(): array
    {
        $fixers = [];

        foreach ($this->registry->all() as $rule) {
            if ($rule instanceof FixableRule) {
                $fixers[$rule->id] = $rule->fixer();
            }
        }

        return $fixers;
    }

    /**
     * @param list<Fix>        $fixes
     * @param array<int, true> $appliedIds
     */
    private function wasFixed(array $fixes, array $appliedIds): bool
    {
        if ($fixes === []) {
            return false;
        }

        return array_all($fixes, fn(Fix $fix): bool => isset($appliedIds[spl_object_id($fix)]));
    }
}
