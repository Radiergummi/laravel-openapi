<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * How `--diff` selects the changed-file set that scopes a lint run.
 */
enum DiffMode
{
    /** `<ref>...HEAD` diff, ref defaulting to the merge-base. */
    case Ref;

    /** Uncommitted work-tree edits (`--diff=working`). */
    case WorkingTree;

    /** Staged index only (`--diff=staged`), the pre-commit scope. */
    case StagedIndex;
}
