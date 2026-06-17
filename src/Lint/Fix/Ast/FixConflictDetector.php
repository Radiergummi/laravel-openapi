<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixSkipReason;
use Radiergummi\OpenApi\Lint\Fix\SkippedFix;

use function array_intersect;
use function in_array;

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
     */
    private function conflicts(Fix $a, Fix $b): bool
    {
        $first = $a->operation;
        $second = $b->operation;

        // An insertion prepends a new attribute group, shifting the flat index space every other
        // attribute op resolves against, so it is never provably independent of another op here.
        if ($first instanceof AddAttribute || $second instanceof AddAttribute) {
            return true;
        }

        // Doc-comment edits touch a different node aspect (comments, not attrGroups), so they are
        // independent of attribute ops; two doc-comment edits on one node both rewrite it.
        if ($first instanceof SetDocComment || $second instanceof SetDocComment) {
            return $first instanceof SetDocComment && $second instanceof SetDocComment;
        }

        if ($first instanceof RemoveAttribute && $second instanceof RemoveAttribute) {
            return array_intersect($first->attributeIndices, $second->attributeIndices) !== [];
        }

        if ($first instanceof SetAttributeArgument && $second instanceof SetAttributeArgument) {
            // Distinct attributes never collide; the same attribute collides only on the same argument
            // (last-wins), distinct arguments add independent named args to that attribute.
            return $first->attributeIndex === $second->attributeIndex
                && $first->argumentName === $second->argumentName;
        }

        // The remaining pairs mix RemoveAttribute with SetAttributeArgument: a conflict iff the set
        // operates on an index the remove drops (the survivor would mutate a shifted/absent attribute).
        [$remove, $set] = $first instanceof RemoveAttribute
            ? [$first, $second]
            : [$second, $first];

        return $remove instanceof RemoveAttribute
            && $set instanceof SetAttributeArgument
            && in_array($set->attributeIndex, $remove->attributeIndices, true);
    }
}
