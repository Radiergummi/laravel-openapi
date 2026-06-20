<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use JsonException;
use Override;
use Radiergummi\OpenApi\Lint\DiffMode;
use Radiergummi\OpenApi\Lint\DiffScope;
use Radiergummi\OpenApi\Lint\Fix\FixRunner;
use Radiergummi\OpenApi\Lint\Fix\FixRunResult;
use Radiergummi\OpenApi\Lint\Fix\UnifiedDiffRenderer;
use Radiergummi\OpenApi\Lint\Formatters\CliFormatter;
use Radiergummi\OpenApi\Lint\Formatters\CoberturaFormatter;
use Radiergummi\OpenApi\Lint\Formatters\Formatter;
use Radiergummi\OpenApi\Lint\Formatters\GithubFormatter;
use Radiergummi\OpenApi\Lint\Formatters\JsonFormatter;
use Radiergummi\OpenApi\Lint\Formatters\LcovFormatter;
use Radiergummi\OpenApi\Lint\Formatters\MarkdownFormatter;
use Radiergummi\OpenApi\Lint\LinterOutputFormat;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintResult;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Lint\Output\FormatTarget;
use Radiergummi\OpenApi\Lint\Output\FormatTargetParser;
use Radiergummi\OpenApi\Lint\Output\OutputChannel;
use Radiergummi\OpenApi\Lint\Output\OutputTarget;
use Radiergummi\OpenApi\Lint\RuleCatalogRenderer;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException as ConsoleLogicException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function explode;
use function fclose;
use function fopen;
use function getenv;
use function implode;
use function is_array;
use function is_numeric;
use function is_resource;
use function is_string;
use function json_encode;
use function ksort;
use function rtrim;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Lints OpenAPI documentation gaps across the API surface.
 *
 * Thin adapter over {@see LintRunner}: parses CLI options into {@see LintOptions} and renders
 * the resulting {@see LintResult} through the chosen formatter.
 */
class LintCommand extends Command
{
    // Name/description use the version-portable string-property form rather than
    // the #[Signature]/#[Description] attributes, which are Laravel 13+ only
    // (Illuminate\Console\Attributes does not exist on Laravel 12).
    protected $signature = 'openapi:lint
        {--level=1 : Severity preset (0–N or "max" for highest defined)}
        {--format=* : Output target(s) as <format>[:<dest>], repeatable (cli|json|github|markdown|cobertura; dest = stdout (default)|stderr|<file>). e.g., --format=github --format=cobertura:coverage.xml}
        {--only= : Restrict to listed rule IDs (comma-separated; `*` globs a family, e.g., migration.*)}
        {--skip= : Restrict to listed rule IDs to exclude (comma-separated; `*` globs a family, e.g., migration.*)}
        {--uri= : Restrict to routes whose URI matches this glob}
        {--path=* : Restrict to routes affected by these source files (repeatable; pre-commit hooks pass $STAGED_FILES)}
        {--diff= : Restrict to routes touched since git-ref (default: merge-base with the default branch; "staged" = index, "working" = work tree)}
        {--no-suppress : Ignore #[IgnoreLint] attributes}
        {--no-validate : Skip the OAS 3.1 meta-schema validation (the spec.invalid rule); faster on large specs}
        {--list : Print the rule catalog instead of linting}
        {--allow-dirty : Allow destructive fixes (--fix=dangerous) to write to an unclean working tree}
        {--spec= : Restrict per-spec rules to this spec; pre-build rules still run}
        {--min-coverage= : Fail when documentation coverage % falls below this threshold (gate-driven exit)}
        {--max-findings= : Fail when the in-scope finding count exceeds this budget}';

    protected $description = 'Lint OpenAPI documentation gaps across the API surface';

    /**
     * Registers `--fix` / `--check` as value-optional options after the string signature is parsed.
     *
     * The Laravel string signature can only express `VALUE_NONE` or `VALUE_REQUIRED`; these need
     * `VALUE_OPTIONAL` so bare `--fix` (level `safe`) and `--fix=dangerous` both parse. Done here,
     * not via Laravel-13-only attributes, to stay portable across Laravel 12 and 13.
     *
     * @throws ConsoleInvalidArgumentException
     * @throws ConsoleLogicException
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $definition = $this->getDefinition();
        $definition->addOption(new InputOption(
            'fix',
            null,
            InputOption::VALUE_OPTIONAL,
            'Apply fixable findings to the source, then report the rest (safe|dangerous)',
            'safe',
        ));
        $definition->addOption(new InputOption(
            'check',
            null,
            InputOption::VALUE_OPTIONAL,
            'Report whether --fix would change anything, without writing (safe|dangerous; CI-safe)',
            'safe',
        ));
        $definition->addOption(new InputOption(
            'show-diff',
            null,
            InputOption::VALUE_NONE,
            'Under --fix/--check, also print a unified diff of the changes each fix would make',
        ));
    }

    /**
     * @throws \LogicException
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws LogicException
     * @throws ProcessRuntimeException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function handle(LintRunner $runner, RuleRegistry $registry, FixRunner $fixRunner): int
    {
        if ($this->option('list')) {
            return $this->renderCatalog($registry);
        }

        // Detect fix-mode by option *presence*, not value: --fix/--check default to 'safe', so
        // $this->option() is non-null even when the flag is absent.
        if ($this->input->hasParameterOption('--fix') || $this->input->hasParameterOption('--check')) {
            return $this->runFix($fixRunner);
        }

        $result = $runner->run($this->buildOptions());

        $this->renderToTargets($result);

        return $result->exitCode;
    }

    /**
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws RuntimeException
     */
    private function renderCatalog(RuleRegistry $registry): int
    {
        $target = $this->resolveTargets()[0];
        $output = $this->openOutput($target->target);

        try {
            new RuleCatalogRenderer()->render($registry, $target->format, $output);
        } finally {
            $this->closeOutput($output);
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the requested output targets. With no `--format`, fall back to a single
     * auto-detected format on stdout (the historical behaviour).
     *
     * @return list<FormatTarget>
     *
     * @throws InvalidArgumentException
     */
    private function resolveTargets(): array
    {
        $raw = $this->option('format');

        $tokens = match (true) {
            is_array($raw) => array_values(array_filter($raw, is_string(...))),
            is_string($raw) && $raw !== '' => [$raw],
            default => [],
        };

        if ($tokens === []) {
            return [new FormatTarget($this->autoDetectFormat(), OutputTarget::fromToken(null))];
        }

        return new FormatTargetParser()->parse($tokens);
    }

    private function autoDetectFormat(): LinterOutputFormat
    {
        return match (true) {
            getenv('GITHUB_ACTIONS') === 'true' => LinterOutputFormat::GitHub,
            $this->output->isDecorated() => LinterOutputFormat::Cli,
            default => LinterOutputFormat::Json,
        };
    }

    /**
     * Open the destination for one target: the command's stdout, its stderr, or a file stream.
     *
     * @throws RuntimeException when the target file cannot be opened for writing
     */
    private function openOutput(OutputTarget $target): OutputInterface
    {
        $console = $this->output->getOutput();

        return match ($target->channel) {
            // Write stdout through the OutputStyle, not the unwrapped OutputInterface: Laravel's
            // PendingCommand captures writeln/write on the OutputStyle for expectsOutput() assertions.
            OutputChannel::Stdout => $this->output,
            OutputChannel::Stderr => $console instanceof ConsoleOutputInterface
                ? $console->getErrorOutput()
                : $console,
            OutputChannel::File => $this->openFile((string) $target->path),
        };
    }

    /**
     * @throws RuntimeException when the file cannot be opened or wrapped as an output stream
     */
    private function openFile(string $path): StreamOutput
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open %s for writing.', $path));
        }

        try {
            return new StreamOutput($handle);
        } catch (ConsoleInvalidArgumentException $exception) {
            throw new RuntimeException(sprintf('Cannot write to %s.', $path), previous: $exception);
        }
    }

    private function closeOutput(OutputInterface $output): void
    {
        if ($output instanceof StreamOutput && is_resource($output->getStream())) {
            fclose($output->getStream());
        }
    }

    /**
     * Apply (`--fix`) or preview (`--check`) the fixable findings, then report what remains.
     *
     * @throws \LogicException
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws LogicException
     * @throws ProcessRuntimeException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function runFix(FixRunner $fixRunner): int
    {
        $dryRun = $this->input->hasParameterOption('--check');
        $applyDestructive = $this->resolveFixLevelIsDangerous($dryRun ? 'check' : 'fix');
        $options = $this->buildOptions();

        if ($options->minCoverage !== null || $options->maxFindings !== null) {
            $this->warn(
                'The coverage gate (--min-coverage / --max-findings) is not evaluated under --fix/--check. '
                . 'Run `openapi:lint` without --fix to gate on coverage.',
            );
        }

        if (!$dryRun && $this->option('show-diff')) {
            $this->warn(
                '--show-diff with --fix previews changes that are also being written. '
                . 'Use --check --show-diff to preview without writing.',
            );
        }

        $outcome = $fixRunner->run(
            $options,
            $dryRun,
            applyDestructive: $applyDestructive,
            allowDirty: (bool) $this->option('allow-dirty'),
        );

        $this->renderFixOutcome($outcome);

        if ($this->option('show-diff')) {
            $this->renderFixDiff($outcome);
        }

        return $outcome->exitCode();
    }

    /**
     * Resolves the `--fix`/`--check` level to whether destructive fixes apply. Bare flag or `=safe`
     * is safe; `=dangerous` opts in; anything else is a usage error.
     *
     * @throws InvalidArgumentException
     */
    private function resolveFixLevelIsDangerous(string $option): bool
    {
        $value = $this->option($option);

        return match ($value) {
            'safe', null, true => false,
            'dangerous' => true,
            default => throw new InvalidArgumentException(sprintf(
                "Unknown --%s level '%s'. Expected 'safe' or 'dangerous'.",
                $option,
                is_string($value) ? $value : '',
            )),
        };
    }

    /**
     * Renders the fix run to every requested target. A JSON target emits the frozen fix-run envelope
     * ({@see FixRunResult::toArray()}); the human formats emit a one-line fixed/remaining/skipped
     * summary plus the remaining findings through their normal formatter.
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws RuntimeException
     */
    private function renderFixOutcome(FixRunResult $outcome): void
    {
        $remaining = new LintResult(
            findings: $outcome->remainingFindings,
            level: $outcome->level,
            exitCode: $outcome->exitCode(),
        );

        foreach ($this->resolveTargets() as $target) {
            $output = $this->openOutput($target->target);

            try {
                if ($target->format === LinterOutputFormat::Json) {
                    $output->writeln($this->encodeFixRun($outcome));

                    continue;
                }

                $this->renderFixSummary($outcome, $target->format, $output);

                if ($outcome->remainingFindings !== []) {
                    $this->formatterFor($target->format)->render($remaining, $output);
                }
            } finally {
                $this->closeOutput($output);
            }
        }
    }

    /**
     * Prints a unified diff of each file a fix would change, to the command's stdout. Kept outside
     * {@see renderFixOutcome()} so the frozen JSON/GitHub/Markdown fix-run envelopes stay untouched:
     * this is supplementary human output, never a machine-readable target.
     */
    private function renderFixDiff(FixRunResult $outcome): void
    {
        $renderer = new UnifiedDiffRenderer();

        foreach ($outcome->fixResult->changes as $change) {
            $diff = $renderer->render($change->file, $change->original, $change->new);

            if ($diff !== '') {
                // One writeln per line so each diff line is a distinct output write: keeps long
                // lines unwrapped and lets line-oriented output assertions match individual hunks.
                $this->output->writeln(explode("\n", rtrim($diff, "\n")));
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function encodeFixRun(FixRunResult $outcome): string
    {
        return json_encode(
            value: $outcome->toArray(),
            flags: JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function buildOptions(): LintOptions
    {
        $uriGlob = $this->option('uri');

        return new LintOptions(
            level: $this->input->hasParameterOption('--level')
                ? (string) $this->option('level')
                : null,
            only: $this->parseList($this->option('only')),
            skip: $this->parseList($this->option('skip')),
            uriGlob: is_string($uriGlob) && $uriGlob !== '' ? $uriGlob : null,
            files: $this->parseFiles($this->option('path')),
            diff: $this->resolveDiffScope(),
            applySuppressions: !$this->option('no-suppress'),
            validateSpec: !$this->option('no-validate'),
            spec: $this->option('spec') ?: null,
            minCoverage: $this->parseMinCoverage(),
            maxFindings: $this->parseMaxFindings(),
        );
    }

    /**
     * @return list<string>
     */
    private function parseList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return $this->trimDropEmpty(explode(',', $raw));
    }

    /**
     * @param array<string> $items
     *
     * @return list<string>
     */
    private function trimDropEmpty(array $items): array
    {
        return array_values(
            array_filter(array_map(trim(...), $items)),
        );
    }

    /**
     * @return list<string>
     */
    private function parseFiles(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return $this->trimDropEmpty(array_filter($raw, is_string(...)));
    }

    /**
     * Map the value-optional `--diff` flag to a {@see DiffScope}, or null when not passed.
     * `option()` cannot distinguish a bare flag from an absent one, so raw input is checked.
     */
    private function resolveDiffScope(): ?DiffScope
    {
        if (!$this->input->hasParameterOption('--diff')) {
            return null;
        }

        $value = $this->option('diff');

        return match ($value) {
            'staged' => new DiffScope(DiffMode::StagedIndex),
            'working' => new DiffScope(DiffMode::WorkingTree),
            default => new DiffScope(
                DiffMode::Ref,
                is_string($value) && $value !== '' ? $value : null,
            ),
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    private function parseMinCoverage(): ?float
    {
        $value = $this->parseFloatOption('min-coverage');

        if ($value !== null && ($value < 0.0 || $value > 100.0)) {
            throw new InvalidArgumentException(
                sprintf('Invalid --min-coverage value: %s. Expected a percentage between 0 and 100.', $value),
            );
        }

        return $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function parseFloatOption(string $name): ?float
    {
        $raw = $this->option($name);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            throw new InvalidArgumentException(
                sprintf('Invalid --%s value: %s. Expected a number.', $name, $raw),
            );
        }

        return (float) $raw;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function parseMaxFindings(): ?int
    {
        $value = $this->parseIntOption('max-findings');

        if ($value !== null && $value < 0) {
            throw new InvalidArgumentException(
                sprintf('Invalid --max-findings value: %d. Expected a non-negative integer.', $value),
            );
        }

        return $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function parseIntOption(string $name): ?int
    {
        $raw = $this->option($name);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            throw new InvalidArgumentException(
                sprintf('Invalid --%s value: %s. Expected a number.', $name, $raw),
            );
        }

        return (int) $raw;
    }

    /**
     * Writes a one-line fixed/remaining/skipped summary in the target's format. GitHub emits a
     * workflow notice, Markdown a bullet, CLI plain prose; the skipped count is surfaced (with the
     * conflict reason) so a re-run hint is actionable.
     */
    private function renderFixSummary(FixRunResult $outcome, LinterOutputFormat $format, OutputInterface $output): void
    {
        $applied = count($outcome->fixResult->applied);
        $remaining = count($outcome->remainingFindings);
        $skipped = count($outcome->fixResult->skipped);
        $verb = $outcome->dryRun ? 'pending' : 'fixed';

        $summary = sprintf('openapi:lint --fix: %d %s, %d remaining, %d skipped', $applied, $verb, $remaining, $skipped);

        if ($skipped > 0) {
            $summary .= sprintf(' (%s); re-run --fix to resolve conflicting fixes', $this->skipReasonBreakdown($outcome));
        }

        $output->writeln($this->formatSummaryLine($summary, $format));

        if ($outcome->withheldDestructiveCount > 0) {
            $output->writeln($this->formatSummaryLine(
                sprintf(
                    '%d finding(s) have potentially destructive fixes. Re-run with --fix=dangerous to apply them.',
                    $outcome->withheldDestructiveCount,
                ),
                $format,
            ));
        }
    }

    private function formatSummaryLine(string $summary, LinterOutputFormat $format): string
    {
        return match ($format) {
            LinterOutputFormat::GitHub => '::notice title=OpenAPI fix::' . $summary,
            LinterOutputFormat::Markdown => '- ' . $summary,
            default => $summary,
        };
    }

    /** A compact `reason: count` breakdown of skipped fixes, e.g. `conflict: 2, node-not-found: 1`. */
    private function skipReasonBreakdown(FixRunResult $outcome): string
    {
        $counts = [];

        foreach ($outcome->fixResult->skipped as $skip) {
            $counts[$skip->reason->value] = ($counts[$skip->reason->value] ?? 0) + 1;
        }

        ksort($counts);

        return implode(', ', array_map(
            static fn(string $reason, int $count): string => sprintf('%s: %d', $reason, $count),
            array_keys($counts),
            array_values($counts),
        ));
    }

    /**
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function renderToTargets(LintResult $result): void
    {
        foreach ($this->resolveTargets() as $target) {
            $formatter = $this->formatterFor($target->format);
            $output = $this->openOutput($target->target);

            try {
                $formatter->render($result, $output);
            } finally {
                $this->closeOutput($output);
            }
        }
    }

    /**
     * @throws BindingResolutionException
     */
    private function formatterFor(LinterOutputFormat $format): Formatter
    {
        return match ($format) {
            LinterOutputFormat::Cli => $this->laravel->make(CliFormatter::class),
            LinterOutputFormat::Markdown => $this->laravel->make(MarkdownFormatter::class),
            LinterOutputFormat::Json => $this->laravel->make(JsonFormatter::class),
            LinterOutputFormat::GitHub => $this->laravel->make(GithubFormatter::class),
            LinterOutputFormat::Cobertura => $this->laravel->make(CoberturaFormatter::class),
            LinterOutputFormat::Lcov => $this->laravel->make(LcovFormatter::class),
        };
    }
}
