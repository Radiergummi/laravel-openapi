<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Comment\Doc;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\RemoveLines;

use function array_any;
use function explode;
use function ltrim;
use function str_contains;
use function str_split;
use function trim;

use const PHP_EOL;

/**
 * Removes `@OA\…` annotation blocks from a PHPDoc docblock — a class's or a controller method's.
 *
 * `@OA` annotations live in comments, outside reflection's attribute model, so this works on physical
 * lines: it locates each contiguous `@OA\…` block (tracking parenthesis depth across lines, ignoring
 * parens inside quoted strings so a `description="see (note)"` doesn't unbalance it) and emits a
 * {@see RemoveLines} per block. When the annotations were the docblock's only meaningful content the
 * whole docblock is dropped; when prose or other tags remain, only the annotation lines go.
 *
 * @internal
 */
final readonly class DocblockAnnotationRemover
{
    /**
     * Emit the edits removing every `@OA\…` block from `$doc`: the whole docblock when nothing else
     * meaningful remains, otherwise one {@see RemoveLines} per block. A null `$doc` yields nothing.
     *
     * @return list<Fix>
     */
    public function removeBlocks(?Doc $doc, string $description, Finding $finding, string $file, FixContext $context): array
    {
        if ($doc === null) {
            return [];
        }

        $lines = explode(PHP_EOL, $context->source($file));
        $docStart = $doc->getStartLine();
        $docEnd = $doc->getEndLine();

        $blocks = $this->locateAnnotationBlocks($lines, $docStart, $docEnd);

        if ($blocks === []) {
            return [];
        }

        if (!$this->docblockHasOtherContent($lines, $docStart, $docEnd, $blocks)) {
            return [new Fix($file, $description, $finding->ruleId, new RemoveLines($docStart, $docEnd))];
        }

        $fixes = [];

        foreach ($blocks as [$blockStart, $blockEnd]) {
            $fixes[] = new Fix($file, $description, $finding->ruleId, new RemoveLines($blockStart, $blockEnd));
        }

        return $fixes;
    }

    /**
     * Every `@OA\…` annotation block in the docblock, as 1-based `[startLine, endLine]` spans, in
     * source order; empty when none is present.
     *
     * @param list<string> $lines
     *
     * @return list<array{int, int}>
     */
    private function locateAnnotationBlocks(array $lines, int $docStart, int $docEnd): array
    {
        $blocks = [];
        $cursor = $docStart;

        while ($cursor <= $docEnd) {
            $block = $this->locateAnnotationBlock($lines, $cursor, $docEnd);

            if ($block === null) {
                break;
            }

            $blocks[] = $block;
            $cursor = $block[1] + 1;
        }

        return $blocks;
    }

    /**
     * The 1-based `[startLine, endLine]` of the first `@OA\…` annotation at or after `$from`, or
     * null when none is present. The end is where the annotation's outermost parenthesis closes.
     *
     * @param list<string> $lines
     *
     * @return null|array{int, int}
     */
    private function locateAnnotationBlock(array $lines, int $from, int $docEnd): ?array
    {
        $blockStart = null;

        for ($line = $from; $line <= $docEnd; $line++) {
            if (str_contains($lines[$line - 1] ?? '', '@OA\\')) {
                $blockStart = $line;

                break;
            }
        }

        if ($blockStart === null) {
            return null;
        }

        $depth = 0;
        $sawParen = false;

        for ($line = $blockStart; $line <= $docEnd; $line++) {
            $depth += $this->parenDelta($lines[$line - 1] ?? '', $sawParen);

            if ($sawParen && $depth <= 0) {
                return [$blockStart, $line];
            }
        }

        // A parenthesis-less annotation (e.g. bare `@OA\Schema`) spans only its own line.
        return [$blockStart, $blockStart];
    }

    /**
     * Net parenthesis depth change across a line, ignoring parens inside single/double-quoted
     * strings. Flags via `$sawParen` once any opening parenthesis has been seen.
     */
    private function parenDelta(string $line, bool &$sawParen): int
    {
        $delta = 0;
        $quote = null;

        foreach (str_split($line) as $character) {
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $delta++;
                $sawParen = true;
            } elseif ($character === ')') {
                $delta--;
            }
        }

        return $delta;
    }

    /**
     * Whether the docblock holds meaningful content (prose or other tags) outside the annotation
     * blocks — in which case only the blocks are removed, not the whole comment.
     *
     * @param list<string>          $lines
     * @param list<array{int, int}> $blocks
     */
    private function docblockHasOtherContent(array $lines, int $docStart, int $docEnd, array $blocks): bool
    {
        for ($line = $docStart; $line <= $docEnd; $line++) {
            if (array_any($blocks, static fn(array $block): bool => $line >= $block[0] && $line <= $block[1])) {
                continue;
            }

            // Strip the comment scaffolding (`/**`, ` * `, ` */`); anything left is real content.
            if (trim(ltrim(trim($lines[$line - 1] ?? ''), '/*')) !== '') {
                return true;
            }
        }

        return false;
    }
}
