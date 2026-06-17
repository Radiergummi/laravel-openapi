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
use function implode;
use function sprintf;
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
    /** @param string $gitBinary The git executable; overridable so tests can force a launch failure. */
    public function __construct(
        private string $gitBinary = 'git',
    ) {}

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

        $process = new Process([$this->gitBinary, 'status', '--porcelain', '--', ...$files], dirname($files[0]));

        try {
            $process->run();
        } catch (ProcessStartFailedException) {
            // Some platforms surface a launch failure as a thrown exception rather than a non-zero
            // exit; both mean git could not verify the tree, so both refuse.
            throw $this->cannotVerify();
        }

        // git absent (exit 127, run via a shell) and not-a-repo (exit 128) both arrive here as a
        // non-zero exit. Neither can promise a trivial revert, so refuse with one honest message.
        if (!$process->isSuccessful()) {
            throw $this->cannotVerify();
        }

        // Any porcelain output for the explicitly-scoped paths means at least one is dirty. Report
        // the files we checked rather than parsing porcelain paths (quoting/rename rules vary).
        if (trim($process->getOutput()) !== '') {
            throw new DirtyWorkingTreeException(sprintf(
                'Refusing to apply destructive fixes: uncommitted changes in %s. Commit or stash '
                . 'them, or re-run with --allow-dirty.',
                implode(', ', $files),
            ));
        }
    }

    private function cannotVerify(): DirtyWorkingTreeException
    {
        return new DirtyWorkingTreeException(
            'Cannot verify a clean working tree (git is unavailable or the target files are not in '
            . 'a git repository). Re-run with --allow-dirty to apply destructive fixes anyway.',
        );
    }
}
