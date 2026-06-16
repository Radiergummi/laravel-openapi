<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Override;

use function strlen;

/**
 * Inserts `$text` verbatim before line `$line` (1-based). Callers must include indentation and
 * the trailing newline.
 */
final class InsertBefore extends FixOperation
{
    public function __construct(
        public readonly int $line,
        public readonly string $text,
    ) {}

    #[Override]
    public function toEdit(string $source): SourceEdit
    {
        $offsets = self::lineStartOffsets($source);
        $at = $offsets[$this->line] ?? strlen($source);

        return new SourceEdit($at, $at, $this->text);
    }
}
