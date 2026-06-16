<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use RuntimeException;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function rename;
use function substr_replace;
use function tempnam;
use function unlink;
use function usort;

use const LOCK_EX;

/**
 * Applies a batch of {@see Fix}es to the working tree. Edits are applied bottom-to-top per file
 * so earlier offsets stay valid; overlapping edits are skipped. Each file is written atomically
 * via a temp file + rename, so a failure never leaves a half-written source.
 *
 * @internal
 */
final readonly class FixApplicator
{
    /**
     * @param list<Fix> $fixes
     *
     * @throws RuntimeException When a modified file cannot be written atomically.
     */
    public function apply(array $fixes, bool $dryRun = false): FixResult
    {
        /** @var array<string, list<Fix>> $byFile */
        $byFile = [];

        foreach ($fixes as $fix) {
            $byFile[$fix->file][] = $fix;
        }

        $applied = [];
        $skipped = [];
        $modifiedFiles = [];

        foreach ($byFile as $file => $fileFixes) {
            $source = file_get_contents($file) ?: '';

            [$accepted, $rejected, $newSource] = $this->applyToSource($source, $fileFixes);

            foreach ($accepted as $fix) {
                $applied[] = $fix;
            }

            foreach ($rejected as $fix) {
                $skipped[] = $fix;
            }

            if ($accepted !== [] && $newSource !== $source) {
                $modifiedFiles[] = $file;

                if (!$dryRun) {
                    $this->write($file, $newSource);
                }
            }
        }

        return new FixResult($applied, $skipped, $modifiedFiles);
    }

    /**
     * @param list<Fix> $fileFixes
     *
     * @return array{list<Fix>, list<Fix>, string}
     */
    private function applyToSource(string $source, array $fileFixes): array
    {
        /** @var list<array{edit: SourceEdit, fix: Fix}> $pairs */
        $pairs = [];

        foreach ($fileFixes as $fix) {
            $pairs[] = ['edit' => $fix->operation->toEdit($source), 'fix' => $fix];
        }

        // Bottom-to-top: largest start offset first. Ties break on the wider edit so a containing
        // edit is preferred over the narrower one it would swallow.
        usort(
            $pairs,
            static fn(array $a, array $b): int
                => $b['edit']->start <=> $a['edit']->start
                ?: $b['edit']->end <=> $a['edit']->end,
        );

        $accepted = [];
        $rejected = [];

        /** @var list<SourceEdit> $acceptedEdits */
        $acceptedEdits = [];
        $result = $source;

        foreach ($pairs as $pair) {
            $edit = $pair['edit'];

            foreach ($acceptedEdits as $existing) {
                if ($edit->overlaps($existing)) {
                    $rejected[] = $pair['fix'];

                    continue 2;
                }
            }

            $acceptedEdits[] = $edit;
            $accepted[] = $pair['fix'];

            $result = substr_replace(
                $result,
                $edit->replacement,
                $edit->start,
                $edit->end - $edit->start,
            );
        }

        return [$accepted, $rejected, $result];
    }

    /**
     * @throws RuntimeException When the temp file cannot be created, written, or renamed into place.
     */
    private function write(string $file, string $contents): void
    {
        $temp = tempnam(dirname($file), 'openapi-fix-');

        if ($temp === false) {
            throw new RuntimeException("Unable to create a temporary file next to {$file}.");
        }

        if (file_put_contents($temp, $contents, LOCK_EX) === false) {
            @unlink($temp);

            throw new RuntimeException("Unable to write fixed contents for {$file}.");
        }

        if (!rename($temp, $file)) {
            @unlink($temp);

            throw new RuntimeException("Unable to replace {$file} with the fixed version.");
        }
    }
}
