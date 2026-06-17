<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * A {@see Fix} that {@see FixApplicator} did not apply, paired with the typed reason it was skipped.
 *
 * @internal
 */
final readonly class SkippedFix
{
    public function __construct(
        public Fix $fix,
        public FixSkipReason $reason,
    ) {}
}
