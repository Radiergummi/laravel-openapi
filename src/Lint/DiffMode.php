<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * How `--diff` selects the changed-file set that scopes a lint run.
 *
 * `Ref` diffs committed history against a git ref (or the default merge-base);
 * `WorkingTree` and `StagedIndex` select uncommitted edits instead — the
 * `--diff=working` / `--diff=staged` pre-commit scopes.
 */
enum DiffMode
{
    /** Committed-vs-ref: `<ref>...HEAD`, with the ref defaulting to the merge-base. */
    case Ref;

    /** Uncommitted work-tree edits: `git diff HEAD` (`--diff=working`). */
    case WorkingTree;

    /** The staged index only: `git diff --cached` (`--diff=staged`), the pre-commit scope. */
    case StagedIndex;
}
