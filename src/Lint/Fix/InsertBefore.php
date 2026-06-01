<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Override;

use function strlen;

/**
 * Insert `$text` immediately before line `$line` (1-based). `$text` is inserted verbatim at the
 * start of the line, so callers include any indentation and the trailing newline that turns it
 * into one or more standalone lines.
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
