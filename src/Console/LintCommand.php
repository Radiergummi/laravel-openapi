<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use JsonException;
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
        {--path= : Restrict to routes matching this URI glob}
        {--diff= : Restrict to routes touched since git-ref (default: merge-base with the repository default branch)}
        {--no-suppress : Ignore #[IgnoreLint] attributes}
        {--list : Print the rule catalog instead of linting}
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
    public function handle(LintRunner $runner, RuleRegistry $registry): int
    {
        if ($this->option('list')) {
            return $this->renderCatalog($registry);
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
        $path = $this->option('path');
        $diffRef = $this->option('diff');

        return new LintOptions(
            level: $this->input->hasParameterOption('--level')
                ? (string) $this->option('level')
                : null,
            only: $this->parseList($this->option('only')),
            skip: $this->parseList($this->option('skip')),
            path: is_string($path) && $path !== '' ? $path : null,
            // --diff is value-optional: a bare `--diff` yields a null value but is still
            // "requested" and must trigger default-ref resolution. option() alone can't tell
            // bare-flag from absent, so check the raw input here.
            diffEnabled: $this->input->hasParameterOption('--diff'),
            diffRef: is_string($diffRef) && $diffRef !== '' ? $diffRef : null,
            applySuppressions: !$this->option('no-suppress'),
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

        return array_values(
            array_filter(array_map(trim(...), explode(',', $raw))),
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
