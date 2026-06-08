<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * Input parameters for a single {@see LintRunner::run()} invocation.
 *
 * Constructed by callers (the artisan command, programmatic consumers, tests) from their own
 * sources of truth. The runner reads these alongside `config('openapi.lint.*')` — explicit
 * `LintOptions` fields override or merge with the config depending on the field; see
 * {@see LintRunner::resolveOnly()} / {@see LintRunner::resolveSkip()} for the merge semantics.
 */
final readonly class LintOptions
{
    /**
     * @param null|int|string $level             Severity preset. Integer 0..N, the sentinel
     *                                           'max' to use the rule with the highest defined
     *                                           level, or null to fall back to the configured
     *                                           `openapi.lint.level` (which defaults to 0).
     * @param list<string>    $only              Rule-ID allowlist from the CLI. Merged with
     *                                           `openapi.lint.enabled_rules`.
     * @param list<string>    $skip              Rule-ID denylist from the CLI. Merged with
     *                                           `openapi.lint.disabled_rules`.
     * @param ?string         $uriGlob           URI glob (--uri); only routes matching this
     *                                           pattern are linted. Null disables.
     * @param list<string>    $files             Explicit source files (--path, repeatable); each
     *                                           is resolved to its affected routes + reachable
     *                                           schemas via the same mechanism as --diff. Empty
     *                                           disables.
     * @param ?DiffScope      $diff              The requested --diff scope, or null when --diff
     *                                           was not passed. A bare --diff is a `Ref`-mode
     *                                           scope with a null ref (deferring to the
     *                                           merge-base default).
     * @param bool            $applySuppressions Whether `#[IgnoreLint]` directives are honoured.
     *                                           False corresponds to --no-suppress on the CLI.
     * @param ?string         $spec              Restrict per-spec rules to one named spec. Null
     *                                           runs them against every spec in SpecRegistry.
     *                                           Pre-build rules always run regardless.
     * @param bool            $validateSpec      Whether the OAS 3.1 meta-schema validation
     *                                           (`spec.invalid`) runs. False corresponds to
     *                                           --no-validate on the CLI; the rule is
     *                                           otherwise non-suppressible.
     * @param ?float          $minCoverage       Minimum coverage percentage gate (--min-coverage).
     *                                           Null falls back to `openapi.lint.min_coverage`.
     *                                           When the resolved value is non-null the command is
     *                                           gate-driven (see {@see LintRunner}).
     * @param ?int            $maxFindings       Maximum allowed finding count (--max-findings).
     *                                           Null falls back to `openapi.lint.max_findings`.
     * @param bool            $migrate           Whether migration rules
     *                                           ({@see \Radiergummi\OpenApi\Contracts\Lint\MigrationRule})
     *                                           run. False corresponds to omitting --migrate on
     *                                           the CLI; these rules are inert otherwise because
     *                                           they trigger a second, inference-only generation.
     */
    public function __construct(
        public int|string|null $level = null,
        public array $only = [],
        public array $skip = [],
        public ?string $uriGlob = null,
        public array $files = [],
        public ?DiffScope $diff = null,
        public bool $applySuppressions = true,
        public bool $validateSpec = true,
        public ?string $spec = null,
        public ?float $minCoverage = null,
        public ?int $maxFindings = null,
        public bool $migrate = false,
    ) {}

    /**
     * Whether any route-scoping flag (`--uri`, `--path`, or `--diff`) narrowed the linted route
     * set, so callers know to compute the in-scope URI set for finding scoping.
     */
    public function isScoped(): bool
    {
        return $this->uriGlob !== null || $this->files !== [] || $this->diff !== null;
    }
}
