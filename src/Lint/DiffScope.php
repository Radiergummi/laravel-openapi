<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * A requested `--diff` scope. Its presence (vs. null on {@see LintOptions}) means `--diff` was
 * requested; `$ref` is used only in {@see DiffMode::Ref} mode and null defers to the merge-base.
 */
final readonly class DiffScope
{
    public function __construct(
        public DiffMode $mode = DiffMode::Ref,
        public ?string $ref = null,
    ) {}
}
