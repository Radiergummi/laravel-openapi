<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Lint;

/**
 * Severity of a lint finding. Lower is more severe.
 *
 * The `--level` threshold includes every severity at or below it (by `->value`). The space is
 * conceptually closed: stricter linting is added as more rules slotting into these buckets, not
 * as new severity tiers.
 */
enum Severity: int
{
    /** A conformant validator rejects the document, or a major consumer fails. */
    case Broken = 0;

    /** Parses, but misrepresents the API, drops information, or misbehaves in tooling. */
    case Degraded = 1;

    /** Correct, but incomplete. */
    case Underspecified = 2;

    /** Correct, but violates a naming, structure, or hygiene convention. */
    case Inconsistent = 3;

    /** Optional polish. */
    case Improvable = 4;
}
