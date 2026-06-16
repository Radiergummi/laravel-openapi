<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Override;

use function strlen;

/**
 * Replace the content of lines `[$startLine, $endLine]` with `$replacement`.
 *
 * Both bounds are 1-based and inclusive. The newline terminating the range is preserved;
 * `$replacement` must not include a trailing newline.
 */
final class ReplaceLines extends FixOperation
{
    public function __construct(
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly string $replacement,
    ) {}

    #[Override]
    public function toEdit(string $source): SourceEdit
    {
        $offsets = self::lineStartOffsets($source);
        $length = strlen($source);

        $start = $offsets[$this->startLine] ?? $length;
        $end = $offsets[$this->endLine + 1] ?? $length;

        // Keep the newline that closes the range so we replace content, not the line break.
        if ($end > $start && $source[$end - 1] === "\n") {
            --$end;
        }

        return new SourceEdit($start, $end, $this->replacement);
    }
}
