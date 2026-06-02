<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Override;

/**
 * Replace the byte span `[$startPos, $endPos)` with `$replacement`.
 *
 * The byte-precise escape hatch for sub-line edits the line operations cannot express: dropping a
 * single attribute from a shared `#[A('x'), A('x')]` group, or (in later phases) rewriting one
 * argument of an attribute. The originating fixer computes the span from php-parser node positions
 * (`getStartFilePos()` / `getEndFilePos() + 1`) and renders the replacement text itself, so the
 * rest of the line is left byte-identical.
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
