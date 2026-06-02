<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Override;

use function strlen;

/**
 * Delete the physical lines `[$startLine, $endLine]` outright, including their trailing newline,
 * so no blank line is left behind. Both bounds are 1-based and inclusive.
 *
 * The common Tier A shape: an attribute that sits alone on its own line(s) is removed by deleting
 * those lines, which leaves every surrounding byte untouched.
 */
final class RemoveLines extends FixOperation
{
    public function __construct(
        public readonly int $startLine,
        public readonly int $endLine,
    ) {}

    #[Override]
    public function toEdit(string $source): SourceEdit
    {
        $offsets = self::lineStartOffsets($source);

        $start = $offsets[$this->startLine] ?? strlen($source);
        $end = $offsets[$this->endLine + 1] ?? strlen($source);

        return new SourceEdit($start, $end, '');
    }
}
