<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use function explode;
use function strlen;

use const PHP_EOL;

/**
 * A single mechanical mutation of a PHP source file.
 *
 * Line-oriented subclasses ({@see RemoveLines}, {@see ReplaceLines}, {@see InsertBefore}) handle
 * whole-line changes; {@see ModifyAttribute} carries a byte-precise span for sub-line edits.
 * Each operation lowers to a {@see SourceEdit} via {@see toEdit()}.
 *
 * Line numbers are 1-based and inclusive.
 */
abstract class FixOperation
{
    /**
     * Returns 1-based line-start byte offsets for `$source`, with a terminal entry at `strlen($source)`.
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

    abstract public function toEdit(string $source): SourceEdit;
}
