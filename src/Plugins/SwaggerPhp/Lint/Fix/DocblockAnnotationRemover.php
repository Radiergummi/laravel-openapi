<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\RemoveLines;

use function explode;
use function ltrim;
use function str_contains;
use function str_split;
use function trim;

use const PHP_EOL;

/**
 * Removes an `@OA\Schema` block from a class's PHPDoc docblock.
 *
 * `@OA` annotations live in comments, outside reflection's attribute model, so this fixer works on
 * physical lines: it locates the class docblock, finds the contiguous span of the `@OA\…`
 * annotation (tracking parenthesis depth across lines, ignoring parens inside quoted strings so a
 * `description="see (note)"` doesn't unbalance it), and emits a {@see RemoveLines}. When the
 * annotation was the docblock's only meaningful content, the whole docblock is dropped; when prose
 * or other tags remain, only the annotation lines are removed.
 *
 * @internal
 */
final readonly class DocblockAnnotationRemover
{
    /**
     * @return list<Fix>
     */
    public function remove(Finding $finding, string $class, string $file, FixContext $context): array
    {
        $classNode = new NodeFinder()->findFirst(
            $context->ast($file),
            static fn(Node $node): bool
                => $node instanceof ClassLike
                && $node->namespacedName?->toString() === $class,
        );

        if (!$classNode instanceof ClassLike) {
            return [];
        }

        $doc = $classNode->getDocComment();

        if ($doc === null) {
            return [];
        }

        $lines = explode(PHP_EOL, $context->source($file));
        $docStart = $doc->getStartLine();
        $docEnd = $doc->getEndLine();

        $block = $this->locateAnnotationBlock($lines, $docStart, $docEnd);

        if ($block === null) {
            return [];
        }

        [$blockStart, $blockEnd] = $block;

        $operation = $this->docblockHasOtherContent($lines, $docStart, $docEnd, $blockStart, $blockEnd)
            ? new RemoveLines($blockStart, $blockEnd)
            : new RemoveLines($docStart, $docEnd);

        return [
            new Fix(
                file: $file,
                description: "Remove redundant @OA\\Schema docblock annotation on {$class}",
                ruleId: $finding->ruleId,
                operation: $operation,
            ),
        ];
    }

    /**
     * The 1-based `[startLine, endLine]` of the `@OA\…` annotation within the docblock, or null
     * when none is present. The end is where the annotation's outermost parenthesis closes.
     *
     * @param list<string> $lines
     *
     * @return null|array{int, int}
     */
    private function locateAnnotationBlock(array $lines, int $docStart, int $docEnd): ?array
    {
        $blockStart = null;

        for ($line = $docStart; $line <= $docEnd; $line++) {
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
     * block — in which case only the block is removed, not the whole comment.
     *
     * @param list<string> $lines
     */
    private function docblockHasOtherContent(
        array $lines,
        int $docStart,
        int $docEnd,
        int $blockStart,
        int $blockEnd,
    ): bool {
        for ($line = $docStart; $line <= $docEnd; $line++) {
            if ($line >= $blockStart && $line <= $blockEnd) {
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
