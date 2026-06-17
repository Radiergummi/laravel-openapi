<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixSkipReason;
use Radiergummi\OpenApi\Lint\Fix\SkippedFix;

/**
 * Partitions a batch of {@see Fix}es into a safe subset to apply and a conflicting subset to skip.
 *
 * Every fix's visitor runs in a single pass over one shared cloned tree, so two operations on the
 * *same* node mutate it in sequence with no guard (silent last-wins, or one op acting on an index
 * another shifted). This detector finds those same-node conflicts up front and keeps only a subset
 * whose effects are provably independent.
 *
 * Grouping is by {@see TargetSelector} identity (`className` + `kind` + `memberName`): two different
 * selectors can never address the same attribute-bearing node, so fixes in different groups never
 * conflict. Within a group the rule is **first-in-list wins**: a fix is kept only when it is provably
 * independent of every already-kept fix; otherwise it is skipped with {@see FixSkipReason::Conflict}.
 * First-wins is deterministic and lets a re-run converge, the skipped op is re-emitted on the next
 * lint pass with no surviving conflictor.
 *
 * @internal
 */
final readonly class FixConflictDetector
{
    /**
     * @param list<Fix> $fixes
     *
     * @return array{kept: list<Fix>, skipped: list<SkippedFix>}
     */
    public function partition(array $fixes): array
    {
        /** @var array<string, list<Fix>> $byTarget */
        $byTarget = [];

        foreach ($fixes as $fix) {
            $byTarget[$this->targetKey($fix)][] = $fix;
        }

        $kept = [];
        $skipped = [];

        foreach ($byTarget as $group) {
            /** @var list<Fix> $groupKept */
            $groupKept = [];

            foreach ($group as $fix) {
                $conflicts = false;

                foreach ($groupKept as $keptFix) {
                    if ($this->conflicts($keptFix, $fix)) {
                        $conflicts = true;

                        break;
                    }
                }

                if ($conflicts) {
                    $skipped[] = new SkippedFix($fix, FixSkipReason::Conflict);

                    continue;
                }

                $groupKept[] = $fix;
                $kept[] = $fix;
            }
        }

        return ['kept' => $kept, 'skipped' => $skipped];
    }

    private function targetKey(Fix $fix): string
    {
        $target = $fix->operation->target;

        return $target->className . "\0" . $target->kind->name . "\0" . ($target->memberName ?? '');
    }

    /**
     * Whether two fixes on the *same* node cannot be safely applied together in one traversal.
     *
     * The op set is small and finite, so the matrix is enumerated explicitly. Bias toward
     * correctness: when independence is not provable, treat the pair as conflicting (a skip is
     * recoverable by re-running; a wrong mutation is not).
     *
     * Every kept op runs against the *same* progressively-mutated tree in one traversal, so an op
     * that changes the member's attribute layout, inserting or removing an attribute group (which
     * re-indexes the flat attribute list), invalidates the indices every later op resolves against.
     * Both {@see AddAttribute} and {@see RemoveAttribute} are therefore treated as conflicting with
     * any other op on the same node; a re-run converges because the skipped op is re-emitted next
     * pass with fresh indices and no surviving layout-changer.
     */
    private function conflicts(Fix $a, Fix $b): bool
    {
        $first = $a->operation;
        $second = $b->operation;

        if ($this->changesLayout($first) || $this->changesLayout($second)) {
            return true;
        }

        // Doc-comment edits touch a different node aspect (comments, not attrGroups), so they are
        // independent of attribute ops; two doc-comment edits on one node both rewrite it.
        if ($first instanceof SetDocComment || $second instanceof SetDocComment) {
            return $first instanceof SetDocComment && $second instanceof SetDocComment;
        }

        // The only remaining pair is SetAttributeArgument vs SetAttributeArgument (neither changes
        // the layout): distinct attributes never collide; the same attribute collides only on the
        // same argument (last-wins), distinct arguments add independent named args to it.
        return $first instanceof SetAttributeArgument
            && $second instanceof SetAttributeArgument
            && $first->attributeIndex === $second->attributeIndex
            && $first->argumentName === $second->argumentName;
    }

    /** Whether the op inserts or removes an attribute group, re-indexing the flat attribute list. */
    private function changesLayout(AstOperation $operation): bool
    {
        return $operation instanceof AddAttribute || $operation instanceof RemoveAttribute;
    }
}
