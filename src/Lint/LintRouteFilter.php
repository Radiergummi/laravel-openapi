<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Str;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionClass;
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
use function class_exists;
use function config_path;
use function explode;
use function fnmatch;
use function in_array;
use function is_string;
use function str_contains;
use function trim;

use const PHP_EOL;

/**
 * Filters the discovered route set for {@see LintRunner} by --uri glob and --path / --diff scope.
 *
 * Default-branch detection tries: `git symbolic-ref refs/remotes/origin/HEAD`, then
 * the first local branch among `main`/`master`/`trunk`, then `HEAD~1`.
 */
#[Scoped]
class LintRouteFilter
{
    /**
     * Apply --uri glob, --path, and --diff filters. A config change widens scope to all routes.
     *
     * @param list<ActionDescriptor> $descriptors
     * @param list<string>           $files       Explicit source files (`--path`), absolute or base-relative.
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
     * True for closure routes (no controller), unresolvable source files, and vendor controllers.
     */
    private static function isVendorOrUnresolvable(ActionDescriptor $descriptor): bool
    {
        if ($descriptor->controller === null && $descriptor->method === null) {
            // The introspector nulls both reflectors when the action method does not exist on an
            // otherwise-resolvable first-party controller. Keep those so the missing-method lint
            // rule can flag them; still drop closures (no controller class) and vendor controllers.
            return self::isVendorOrUnresolvableMissingMethod($descriptor);
        }

        $file = $descriptor->method?->getFileName()
            ?? $descriptor->controller?->getFileName();

        if ($file === false || $file === null) {
            return true;
        }

        return str_contains($file, '/vendor/');
    }

    /**
     * Whether a both-reflectors-null descriptor is a closure/vendor route rather than a first-party
     * controller with a missing action method.
     */
    private static function isVendorOrUnresolvableMissingMethod(ActionDescriptor $descriptor): bool
    {
        $controllerClass = $descriptor->route->getControllerClass();

        if ($controllerClass === null || !class_exists($controllerClass)) {
            return true;
        }

        $file = new ReflectionClass($controllerClass)->getFileName();

        return $file === false || str_contains($file, '/vendor/');
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
     * Resolves a `Ref`-mode scope with no ref to a concrete merge-base ref; passes through otherwise.
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
     * @param list<string> $changedFiles
     */
    private function infraTouched(array $changedFiles): bool
    {
        $configPath = $this->normaliseToBaseRelative(config_path('openapi.php'));

        return in_array($configPath, $changedFiles, true);
    }

    /**
     * Normalises to base-relative form so `--path` and `git diff --name-only` outputs compare identically.
     */
    private function normaliseToBaseRelative(string $file): string
    {
        return Str::after($file, base_path() . '/');
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
