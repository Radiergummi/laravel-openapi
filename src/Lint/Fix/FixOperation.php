<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use function explode;
use function strlen;

use const PHP_EOL;

/**
 * A single mechanical mutation of a PHP source file.
 *
 * The sealed hierarchy is deliberately small: line-oriented edits ({@see RemoveLines},
 * {@see ReplaceLines}, {@see InsertBefore}) cover whole-line changes — the common shape for
 * attribute and doc-comment fixes — while {@see ModifyAttribute} carries a byte-precise span for
 * sub-line edits (e.g. dropping one attribute from a shared `#[A, B]` group). Each operation lowers
 * to a single {@see SourceEdit} against a concrete source string via {@see toEdit()}, so
 * {@see FixApplicator} never has to special-case the operation type.
 *
 * Line numbers are 1-based and inclusive, matching reflection and php-parser conventions.
 */
abstract class FixOperation
{
    abstract public function toEdit(string $source): SourceEdit;

    /**
     * Byte offsets of the start of each 1-based line, plus a terminal entry at `strlen($source)`
     * for the position just past the final line. Index `n` is the start of line `n`; index
     * `lastLine + 1` is end-of-source. Used to translate line numbers into byte ranges.
     *
     * @return array<int, int>
     */
    protected static function lineStartOffsets(string $source): array
    {
        $offsets = [1 => 0];
        $line = 1;
        $cursor = 0;

        foreach (explode(PHP_EOL, $source) as $content) {
            // +1 for the "\n" that explode() consumed; the final segment has no trailing newline,
            // but the terminal offset is clamped to strlen() below regardless.
            $cursor += strlen($content . PHP_EOL);
            $offsets[++$line] = $cursor;
        }

        $offsets[$line] = strlen($source);

        return $offsets;
    }
}
