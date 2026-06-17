<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

use function dirname;
use function explode;
use function implode;
use function sprintf;
use function substr;
use function trim;

/**
 * Refuses to apply destructive fixes unless their target files are clean and git-tracked, so an
 * unwanted change can be reverted with `git checkout`. The safe-only path never calls this, so the
 * common run carries no git dependency or overhead.
 *
 * @internal
 */
final readonly class WorkingTreeGuard
{
    /**
     * Asserts every target file is clean in git, or throws {@see DirtyWorkingTreeException}.
     *
     * `$allowDirty` (the `--allow-dirty` flag) skips the check entirely. With no target files there
     * is nothing destructive to guard. Two git failure modes both refuse, since neither can promise
     * a trivial revert: git is unavailable (the binary cannot launch) or the path is not a repo.
     *
     * @param list<string> $files
     *
     * @throws DirtyWorkingTreeException
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    public function assertClean(array $files, bool $allowDirty): void
    {
        if ($allowDirty || $files === []) {
            return;
        }

        $process = new Process(['git', 'status', '--porcelain', '--', ...$files], dirname($files[0]));

        try {
            $process->run();
        } catch (ProcessStartFailedException) {
            throw new DirtyWorkingTreeException(
                'Cannot verify a clean working tree because git is unavailable. Re-run with '
                . '--allow-dirty to apply destructive fixes anyway.',
            );
        }

        if (!$process->isSuccessful()) {
            throw new DirtyWorkingTreeException(
                'Cannot verify a clean working tree because the target files are not in a git '
                . 'repository. Re-run with --allow-dirty to apply destructive fixes anyway.',
            );
        }

        $dirty = $this->dirtyPaths($process->getOutput());

        if ($dirty !== []) {
            throw new DirtyWorkingTreeException(sprintf(
                'Refusing to apply destructive fixes: uncommitted changes in %s. Commit or stash '
                . 'them, or re-run with --allow-dirty.',
                implode(', ', $dirty),
            ));
        }
    }

    /**
     * The paths reported dirty by `git status --porcelain` (each line is `XY <path>`).
     *
     * @return list<string>
     */
    private function dirtyPaths(string $output): array
    {
        $paths = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') {
                continue;
            }

            // Porcelain v1: two status columns, a space, then the path; drop the 3-char prefix.
            $paths[] = trim(substr($line, 3));
        }

        return $paths;
    }
}
