<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Str;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

use function array_filter;
use function array_map;
use function array_values;
use function base_path;
use function config_path;
use function explode;
use function fnmatch;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function str_contains;
use function trim;

use const PHP_EOL;

/**
 * Filters the discovered route set for {@see LintRunner} using --path glob and --diff git
 * detection. Extracted from {@see LintCommand} so the filter is unit-testable; the runner uses
 * it via composition.
 *
 * The default-branch detection delegates to git itself:
 *  1. `git symbolic-ref refs/remotes/origin/HEAD` — the value `git clone` sets to the upstream
 *     default branch (usually `origin/main` or `origin/master`).
 *  2. The first existing local branch among `main`, `master`, `trunk`.
 *  3. Fallback: `HEAD~1`.
 *
 * Consumers can supply an explicit ref via {@see LintOptions::$diffRef}; the detection runs
 * only when --diff is requested without a value.
 */
#[Scoped]
class LintRouteFilter
{
    /**
     * Apply the scoping filters in order: --uri (glob match against route URI), then the
     * file-based scope formed by --path (an explicit file list) unioned with --diff (files
     * changed since the diff ref). Both file sources feed the same descriptor-affected check, so
     * `--path Foo.php` is the manual form of `--diff`'s changed-file list. When the resulting
     * changed-file set includes the published OpenAPI config, every route is preserved because a
     * config change can affect every operation's output.
     *
     * @param list<ActionDescriptor> $descriptors
     * @param list<string>           $files       Explicit source files (`--path`), absolute or
     *                                            base-relative; normalised to base-relative here.
     *
     * @return list<ActionDescriptor>
     *
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    public function filter(array $descriptors, ?string $uriGlob, array $files, bool $diffEnabled, ?string $diffRef): array
    {
        $descriptors = $this->dropClosureAndVendorRoutes($descriptors);

        if (is_string($uriGlob) && $uriGlob !== '') {
            $descriptors = array_values(
                array_filter(
                    $descriptors,
                    static fn(ActionDescriptor $descriptor): bool
                        => fnmatch($uriGlob, $descriptor->route->uri()),
                ),
            );
        }

        if ($files === [] && !$diffEnabled) {
            return $descriptors;
        }

        $changedFiles = array_map($this->normaliseToBaseRelative(...), $files);

        if ($diffEnabled) {
            $ref = $diffRef === null || $diffRef === ''
                ? $this->resolveDefaultDiffRef()
                : $diffRef;

            $changedFiles = [...$changedFiles, ...$this->changedFilesSince($ref)];
        }

        if (!$this->infraTouched($changedFiles)) {
            $descriptors = array_values(
                array_filter(
                    $descriptors,
                    fn(ActionDescriptor $descriptor): bool
                        => $this->descriptorAffectedByChanges($descriptor, $changedFiles),
                ),
            );
        }

        return $descriptors;
    }

    /**
     * Normalise a CLI-supplied `--path` value to the base-relative form `git diff --name-only`
     * emits, so explicit files and diff-derived files compare identically. Absolute paths under
     * the project root are made relative; values already relative pass through unchanged.
     */
    private function normaliseToBaseRelative(string $file): string
    {
        return Str::after($file, base_path() . '/');
    }

    /**
     * @param list<ActionDescriptor> $descriptors
     *
     * @return list<ActionDescriptor>
     */
    private function dropClosureAndVendorRoutes(array $descriptors): array
    {
        return array_values(
            array_filter(
                $descriptors,
                static fn(ActionDescriptor $descriptor): bool
                    => !self::isVendorOrUnresolvable($descriptor),
            ),
        );
    }

    /**
     * True for descriptors the lint pipeline must skip: closure routes (no controller),
     * routes whose source file cannot be resolved, and routes whose controller lives in
     * a vendor directory. Shared between the descriptor-level filter and the
     * generator-level filter so the two cannot drift apart.
     */
    private static function isVendorOrUnresolvable(ActionDescriptor $descriptor): bool
    {
        if ($descriptor->controller === null && $descriptor->method === null) {
            return true;
        }

        $file = $descriptor->method?->getFileName()
            ?? $descriptor->controller?->getFileName();

        if ($file === false || $file === null) {
            return true;
        }

        return str_contains($file, '/vendor/');
    }

    /**
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    protected function resolveDefaultDiffRef(): string
    {
        $branch = $this->detectDefaultBranch();

        if ($branch !== null) {
            $process = new Process(['git', 'merge-base', 'HEAD', $branch]);
            $process->run();
            $output = trim($process->getOutput());

            if ($output !== '' && $process->isSuccessful()) {
                return $output;
            }
        }

        return 'HEAD~1';
    }

    /**
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    protected function detectDefaultBranch(): ?string
    {
        $symbolic = new Process(['git', 'symbolic-ref', '--short', 'refs/remotes/origin/HEAD']);
        $symbolic->run();

        if ($symbolic->isSuccessful()) {
            $value = trim($symbolic->getOutput());

            if ($value !== '') {
                return Str::after($value, 'origin/');
            }
        }

        $local = new Process([
            'git',
            'branch',
            '--list',
            '--format=%(refname:short)',
            'main',
            'master',
            'trunk',
        ]);
        $local->run();

        if ($local->isSuccessful()) {
            foreach (explode(PHP_EOL, $local->getOutput()) as $line) {
                $candidate = trim($line);

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     *
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    protected function changedFilesSince(string $ref): array
    {
        $process = new Process($this->diffCommand($ref));
        $process->run();

        return array_values(
            array_filter(array_map(trim(...), explode(PHP_EOL, $process->getOutput()))),
        );
    }

    /**
     * The git argv for a given diff ref. The `working`/`staged` sentinels select uncommitted
     * edits instead of a committed-vs-ref diff: `working` ≈ everything dirty in the work tree
     * (--diff=working), `staged` ≈ the index only (--diff=staged, the pre-commit scope).
     *
     * @return list<string>
     */
    protected function diffCommand(string $ref): array
    {
        return match ($ref) {
            'working' => ['git', 'diff', '--name-only', 'HEAD'],
            'staged' => ['git', 'diff', '--cached', '--name-only'],
            default => ['git', 'diff', '--name-only', $ref . '...HEAD'],
        };
    }

    /**
     * Returns true when a changed file is the published OpenAPI config — a change there can
     * affect every operation's output, so the per-descriptor diff filter is bypassed.
     *
     * @param list<string> $changedFiles
     */
    private function infraTouched(array $changedFiles): bool
    {
        $configPath = Str::after(config_path('openapi.php'), base_path() . '/');

        return in_array($configPath, $changedFiles, true);
    }

    /**
     * @param list<string> $changedFiles
     */
    private function descriptorAffectedByChanges(ActionDescriptor $descriptor, array $changedFiles): bool
    {
        if ($descriptor->method === null) {
            return false;
        }

        $controllerFile = $descriptor->method->getFileName();

        if ($controllerFile === false) {
            return false;
        }

        return in_array(Str::after($controllerFile, base_path() . '/'), $changedFiles, true);
    }

    /**
     * Build the OpenApiGenerator filter list that restricts generation to the filtered route
     * set. Returns include filters (closure returning true means "skip this descriptor"). The
     * vendor/closure exclusion always applies; the URI allowlist is layered on top when --path
     * or --diff narrowed the descriptor list.
     *
     * @param list<ActionDescriptor> $descriptors
     *
     * @return list<callable(ActionDescriptor): bool>
     */
    public function buildGeneratorFilters(array $descriptors, ?string $path, bool $diffEnabled): array
    {
        $filters = [];

        $filters[] = static fn(ActionDescriptor $descriptor): bool
            => self::isVendorOrUnresolvable($descriptor);

        if ((is_string($path) && $path !== '') || $diffEnabled) {
            $allowed = [];

            foreach ($descriptors as $descriptor) {
                $key = sprintf(
                    '%s|%s',
                    $descriptor->route->uri(),
                    implode(',', $descriptor->route->methods()),
                );
                $allowed[$key] = true;
            }

            $filters[] = static fn(ActionDescriptor $descriptor): bool
                => !isset(
                    $allowed[sprintf(
                        '%s|%s',
                        $descriptor->route->uri(),
                        implode(',', $descriptor->route->methods()),
                    )],
                );
        }

        return $filters;
    }
}
