<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * A requested `--diff` scope: a {@see DiffMode} plus, for {@see DiffMode::Ref}, the optional git
 * ref to diff against. The presence of a `DiffScope` (vs. null on {@see LintOptions}) means
 * `--diff` was requested at all; `$ref` is read only in `Ref` mode and a null there defers to the
 * merge-base default.
 */
final readonly class DiffScope
{
    public function __construct(
        public DiffMode $mode = DiffMode::Ref,
        public ?string $ref = null,
    ) {}
}
