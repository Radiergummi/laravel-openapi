<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use JsonException;
use Radiergummi\OpenApi\Lint\Fix\FixRunner;
use Radiergummi\OpenApi\Lint\Fix\FixRunResult;
use Radiergummi\OpenApi\Lint\Formatters\CliFormatter;
use Radiergummi\OpenApi\Lint\Formatters\Formatter;
use Radiergummi\OpenApi\Lint\Formatters\GithubFormatter;
use Radiergummi\OpenApi\Lint\Formatters\JsonFormatter;
use Radiergummi\OpenApi\Lint\LinterOutputFormat;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintResult;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Lint\RuleCatalogRenderer;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function getenv;
use function implode;
use function is_string;
use function sprintf;

/**
 * Lints OpenAPI documentation gaps across the API surface.
 *
 * Thin adapter over {@see LintRunner}: parses CLI options into a {@see LintOptions}, hands off
 * to the runner, and renders the resulting {@see LintResult} through the chosen formatter.
 */
class LintCommand extends Command
{
    // Name/description use the version-portable string-property form rather than
    // the #[Signature]/#[Description] attributes, which are Laravel 13+ only
    // (Illuminate\Console\Attributes does not exist on Laravel 12).
    protected $signature = 'openapi:lint
        {--level=1 : Severity preset (0–N or "max" for highest defined)}
        {--format= : Output format (cli|json|github|markdown; auto-detected by default)}
        {--only= : Restrict to listed rule IDs (comma-separated)}
        {--skip= : Restrict to listed rule IDs to exclude (comma-separated)}
        {--uri= : Restrict to routes whose URI matches this glob}
        {--path=* : Restrict to routes affected by these source files (repeatable; pre-commit hooks pass $STAGED_FILES)}
        {--diff= : Restrict to routes touched since git-ref (default: merge-base with the default branch; "staged" = index, "working" = work tree)}
        {--no-suppress : Ignore #[IgnoreLint] attributes}
        {--no-validate : Skip the OAS 3.1 meta-schema validation (the spec.invalid rule); faster on large specs}
        {--list : Print the rule catalog instead of linting}
        {--fix : Apply fixable findings to the source, then report the rest}
        {--check : Report whether --fix would change anything, without writing (CI-safe)}
        {--spec= : Restrict per-spec rules to this spec; pre-build rules still run}';

    protected $description = 'Lint OpenAPI documentation gaps across the API surface';

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

        if ($this->option('fix') || $this->option('check')) {
            return $this->runFix($fixRunner);
        }

        $result = $runner->run($this->buildOptions());

        $formatter = $this->resolveFormatter();
        $formatter->render(
            $result->findings,
            $result->level,
            $result->exitCode,
            $this->output->getOutput(),
        );

        return $result->exitCode;
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
        $dryRun = (bool) $this->option('check');
        $outcome = $fixRunner->run($this->buildOptions(), $dryRun);

        $this->renderFixSummary($outcome, $dryRun);

        if ($outcome->remainingFindings !== []) {
            $this->resolveFormatter()->render(
                $outcome->remainingFindings,
                $outcome->level,
                $outcome->exitCode(),
                $this->output->getOutput(),
            );
        }

        return $outcome->exitCode();
    }

    private function renderFixSummary(FixRunResult $outcome, bool $dryRun): void
    {
        $files = $outcome->fixResult->modifiedFiles;
        $count = count($outcome->fixResult->applied);

        if ($count === 0) {
            $this->info('openapi:lint --fix: nothing to fix.');

            return;
        }

        if ($dryRun) {
            $this->warn(sprintf(
                '%d fixable finding(s) pending across %d file(s). Run `php artisan openapi:lint --fix` to apply them.',
                $count,
                count($files),
            ));

            return;
        }

        $this->info(sprintf('Fixed %d finding(s) across %d file(s):', $count, count($files)));

        foreach ($files as $file) {
            $this->line('  ' . $file);
        }

        if ($outcome->fixResult->skipped !== []) {
            $this->warn(sprintf(
                '%d fix(es) skipped due to overlapping edits; re-run --fix to resolve the rest.',
                count($outcome->fixResult->skipped),
            ));
        }

        $this->line('Run your formatter on the changes, e.g. `vendor/bin/pint --dirty`.');
    }

    /**
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    private function renderCatalog(RuleRegistry $registry): int
    {
        // Pass $this->output (the OutputStyle) rather than ->getOutput() — Laravel's
        // PendingCommand mocks writeln/write on the OutputStyle for assertion capture, so
        // writes to the unwrapped OutputInterface bypass tests that use
        // expectsOutputToContain().
        new RuleCatalogRenderer()->render(
            $registry,
            $this->resolveFormat(),
            $this->output,
        );

        return self::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function resolveFormat(): LinterOutputFormat
    {
        $formatIdentifier = $this->option('format');

        if (!is_string($formatIdentifier) || $formatIdentifier === '') {
            return match (true) {
                getenv('GITHUB_ACTIONS') === 'true' => LinterOutputFormat::GitHub,
                $this->output->isDecorated() => LinterOutputFormat::Cli,
                default => LinterOutputFormat::Json,
            };
        }

        return LinterOutputFormat::tryFrom($formatIdentifier)
            ?? throw new InvalidArgumentException(
                sprintf(
                    'Invalid format: %s. Allowed values are: %s.',
                    $formatIdentifier,
                    implode(', ', array_column(LinterOutputFormat::cases(), 'value')),
                ),
            );
    }

    private function buildOptions(): LintOptions
    {
        $uriGlob = $this->option('uri');
        $diffRef = $this->option('diff');

        return new LintOptions(
            level: $this->input->hasParameterOption('--level')
                ? (string) $this->option('level')
                : null,
            only: $this->parseList($this->option('only')),
            skip: $this->parseList($this->option('skip')),
            uriGlob: is_string($uriGlob) && $uriGlob !== '' ? $uriGlob : null,
            files: $this->parseFiles($this->option('path')),
            // --diff is value-optional: a bare `--diff` yields a null value but is still
            // "requested" and must trigger default-ref resolution. option() alone can't tell
            // bare-flag from absent, so check the raw input here.
            diffEnabled: $this->input->hasParameterOption('--diff'),
            diffRef: is_string($diffRef) && $diffRef !== '' ? $diffRef : null,
            applySuppressions: !$this->option('no-suppress'),
            validateSpec: !$this->option('no-validate'),
            spec: $this->option('spec') ?: null,
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
     * Normalise the repeatable `--path=*` option into a clean file list. Symfony yields an array
     * of strings (or an empty array when absent); drop blanks and trim each entry.
     *
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
     * Trim each entry and drop the empties, reindexed.
     *
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
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     */
    private function resolveFormatter(): Formatter
    {
        $format = $this->resolveFormat();

        return match ($format) {
            LinterOutputFormat::Markdown,
            LinterOutputFormat::Cli => $this->laravel->make(CliFormatter::class),
            LinterOutputFormat::Json => $this->laravel->make(JsonFormatter::class),
            LinterOutputFormat::GitHub => $this->laravel->make(GithubFormatter::class),
        };
    }
}
