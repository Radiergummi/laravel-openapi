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
     * @param ?string         $path              URI glob; only routes matching this pattern are
     *                                           linted. Null disables.
     * @param bool            $diffEnabled       True when --diff was passed (even without a
     *                                           value); the runner then computes the default
     *                                           ref if $diffRef is null.
     * @param ?string         $diffRef           Explicit git ref for --diff. Null and
     *                                           $diffEnabled = true triggers default resolution.
     * @param bool            $applySuppressions Whether `#[IgnoreLint]` directives are honoured.
     *                                           False corresponds to --no-suppress on the CLI.
     * @param ?string         $spec              Restrict per-spec rules to one named spec. Null
     *                                           runs them against every spec in SpecRegistry.
     *                                           Pre-build rules always run regardless.
     * @param bool            $validateSpec      Whether the OAS 3.1 meta-schema validation
     *                                           (`spec.invalid`) runs. False corresponds to
     *                                           --no-spec-validation on the CLI; the rule is
     *                                           otherwise non-suppressible.
     */
    public function __construct(
        public int|string|null $level = null,
        public array $only = [],
        public array $skip = [],
        public ?string $path = null,
        public bool $diffEnabled = false,
        public ?string $diffRef = null,
        public bool $applySuppressions = true,
        public bool $validateSpec = true,
        public ?string $spec = null,
    ) {}
}
