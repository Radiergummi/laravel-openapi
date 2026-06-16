<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Override;

/**
 * Replace the byte span `[$startPos, $endPos)` with `$replacement`.
 *
 * For sub-line edits (e.g. dropping one attribute from a shared group) that line-level operations
 * cannot express. The span comes from php-parser node positions.
 */
final class ModifyAttribute extends FixOperation
{
    public function __construct(
        public readonly int $startPos,
        public readonly int $endPos,
        public readonly string $replacement,
    ) {}

    #[Override]
    public function toEdit(string $source): SourceEdit
    {
        return new SourceEdit($this->startPos, $this->endPos, $this->replacement);
    }
}
