<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * The original and rewritten contents of one file a {@see FixApplicator} run would modify.
 *
 * Captured so `--check --show-diff` can render a unified diff of the pending edit; the write path
 * itself does not need it. Holds full file contents, not a diff, so the renderer stays decoupled.
 *
 * @internal
 */
final readonly class FileChange
{
    public function __construct(
        public string $file,
        public string $original,
        public string $new,
    ) {}
}
