<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use function array_fill;
use function count;
use function explode;
use function implode;
use function max;
use function sprintf;

/**
 * Renders a line-based unified diff between two versions of a file.
 *
 * A small built-in printer so `--show-diff` can preview a pending fix without promoting the
 * dev-only `sebastian/diff` to a runtime dependency. Output is a single all-context hunk, which is
 * sufficient for the small, localised edits the fixers produce.
 *
 * @internal
 */
final readonly class UnifiedDiffRenderer
{
    /**
     * Returns a unified diff of `$original` → `$new`, or an empty string when they are identical.
     */
    public function render(string $file, string $original, string $new): string
    {
        if ($original === $new) {
            return '';
        }

        $originalLines = explode("\n", $original);
        $newLines = explode("\n", $new);

        $lines = [
            sprintf('--- a/%s', $file),
            sprintf('+++ b/%s', $file),
            sprintf('@@ -1,%d +1,%d @@', count($originalLines), count($newLines)),
            ...$this->diffLines($originalLines, $newLines),
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $original
     * @param list<string> $new
     *
     * @return list<string>
     */
    private function diffLines(array $original, array $new): array
    {
        $lcs = $this->longestCommonSubsequence($original, $new);

        $rows = [];
        $i = 0;
        $j = 0;

        foreach ($lcs as $common) {
            while ($i < count($original) && $original[$i] !== $common) {
                $rows[] = '-' . $original[$i++];
            }

            while ($j < count($new) && $new[$j] !== $common) {
                $rows[] = '+' . $new[$j++];
            }

            $rows[] = ' ' . $common;
            $i++;
            $j++;
        }

        while ($i < count($original)) {
            $rows[] = '-' . $original[$i++];
        }

        while ($j < count($new)) {
            $rows[] = '+' . $new[$j++];
        }

        return $rows;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    private function longestCommonSubsequence(array $a, array $b): array
    {
        $rows = count($a);
        $columns = count($b);

        /** @var array<int, array<int, int>> $table */
        $table = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $columns - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $sequence = [];
        $i = 0;
        $j = 0;

        while ($i < $rows && $j < $columns) {
            if ($a[$i] === $b[$j]) {
                $sequence[] = $a[$i];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $sequence;
    }
}
