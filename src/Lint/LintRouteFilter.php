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
use function in_array;
use function is_string;
use function str_contains;
use function trim;

use const PHP_EOL;

/**
 * Filters the discovered route set for {@see LintRunner} using the --uri glob and the file-based
 * --path / --diff scope. Extracted from {@see LintCommand} so the filter is unit-testable; the
 * runner uses it via composition.
 *
 * The default-branch detection delegates to git itself:
 *  1. `git symbolic-ref refs/remotes/origin/HEAD` — the value `git clone` sets to the upstream
 *     default branch (usually `origin/main` or `origin/master`).
 *  2. The first existing local branch among `main`, `master`, `trunk`.
 *  3. Fallback: `HEAD~1`.
 *
 * Consumers supply the scope via a {@see DiffScope} on {@see LintOptions::$diff}; the default-ref
 * detection runs only for a {@see DiffMode::Ref} scope whose ref is null.
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
     * @param ?DiffScope             $diff        The `--diff` scope, or null when not requested.
     *
     * @return list<ActionDescriptor>
     *
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    public function filter(array $descriptors, ?string $uriGlob, array $files, ?DiffScope $diff): array
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

        if ($files === [] && $diff === null) {
            return $descriptors;
        }

        $changedFiles = array_map($this->normaliseToBaseRelative(...), $files);

        if ($diff !== null) {
            $changedFiles = [...$changedFiles, ...$this->changedFilesSince($this->resolveRef($diff))];
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
     * Resolve a `Ref`-mode scope with no ref to a concrete merge-base ref, so {@see diffCommand}
     * stays a pure mode→argv mapping. Work-tree modes and explicit refs pass through unchanged.
     *
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    private function resolveRef(DiffScope $diff): DiffScope
    {
        if ($diff->mode === DiffMode::Ref && ($diff->ref === null || $diff->ref === '')) {
            return new DiffScope(DiffMode::Ref, $this->resolveDefaultDiffRef());
        }

        return $diff;
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
    protected function changedFilesSince(DiffScope $diff): array
    {
        $process = new Process($this->diffCommand($diff));
        $process->run();

        return array_values(
            array_filter(array_map(trim(...), explode(PHP_EOL, $process->getOutput()))),
        );
    }

    /**
     * The git argv for a diff scope. `WorkingTree`/`StagedIndex` select uncommitted edits; `Ref`
     * diffs `<ref>...HEAD` and expects a concrete ref (see {@see resolveRef}).
     *
     * @return list<string>
     */
    protected function diffCommand(DiffScope $diff): array
    {
        return match ($diff->mode) {
            DiffMode::WorkingTree => ['git', 'diff', '--name-only', 'HEAD'],
            DiffMode::StagedIndex => ['git', 'diff', '--cached', '--name-only'],
            DiffMode::Ref => ['git', 'diff', '--name-only', $diff->ref . '...HEAD'],
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
        $configPath = $this->normaliseToBaseRelative(config_path('openapi.php'));

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

        return in_array($this->normaliseToBaseRelative($controllerFile), $changedFiles, true);
    }
}
