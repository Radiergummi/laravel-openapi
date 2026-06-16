<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * Input parameters for a single {@see LintRunner::run()} invocation.
 *
 * Fields override or merge with `config('openapi.lint.*')`. See
 * {@see LintRunner::resolveOnly()} / {@see LintRunner::resolveSkip()} for merge semantics.
 */
final class LintOptions
{
    /** True when any route-scoping flag narrowed the linted route set. */
    public bool $isScoped {
        get => $this->uriGlob !== null || $this->files !== [] || $this->diff !== null;
    }

    /**
     * @param null|int|string $level             Severity preset: 0..N, `'max'`, or null (config default).
     * @param list<string>    $only              Rule-ID allowlist from the CLI.
     * @param list<string>    $skip              Rule-ID denylist from the CLI.
     * @param ?string         $uriGlob           URI glob (--uri). Null disables.
     * @param list<string>    $files             Explicit source files (--path). Empty disables.
     * @param ?DiffScope      $diff              Requested --diff scope; null when flag was not passed.
     * @param bool            $applySuppressions Whether `#[IgnoreLint]` directives are honoured.
     * @param ?string         $spec              Restrict per-spec rules to one named spec. Null runs all.
     * @param bool            $validateSpec      Whether OAS 3.1 meta-schema validation runs.
     * @param ?float          $minCoverage       Minimum coverage gate (--min-coverage). Null uses config.
     * @param ?int            $maxFindings       Maximum finding count (--max-findings). Null uses config.
     */
    public function __construct(
        public readonly int|string|null $level = null,
        public readonly array $only = [],
        public readonly array $skip = [],
        public readonly ?string $uriGlob = null,
        public readonly array $files = [],
        public readonly ?DiffScope $diff = null,
        public readonly bool $applySuppressions = true,
        public readonly bool $validateSpec = true,
        public readonly ?string $spec = null,
        public readonly ?float $minCoverage = null,
        public readonly ?int $maxFindings = null,
    ) {}
}
