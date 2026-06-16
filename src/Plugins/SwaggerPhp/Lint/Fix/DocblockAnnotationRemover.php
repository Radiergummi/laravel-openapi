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
 * Removes `@OA\…` annotation blocks from a PHPDoc docblock.
 *
 * Works on physical lines, tracking parenthesis depth (ignoring parens inside quoted strings).
 * When annotations are the only content the whole docblock is dropped; otherwise only the
 * annotation lines go.
 *
 * @internal
 */
final readonly class DocblockAnnotationRemover
{
    /**
     * @return list<Fix>
     */
    public function removeBlocks(
        ?Doc $doc,
        string $description,
        Finding $finding,
        string $file,
        FixContext $context,
    ): array {
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

        // A parenthesis-less annotation (e.g., bare `@OA\Schema`) spans only its own line.
        return [$blockStart, $blockStart];
    }

    /**
     * Net parenthesis depth change for a line, ignoring parens inside quoted strings.
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
     * @param list<string>          $lines
     * @param list<array{int, int}> $blocks
     */
    private function docblockHasOtherContent(array $lines, int $docStart, int $docEnd, array $blocks): bool
    {
        for ($line = $docStart; $line <= $docEnd; $line++) {
            if (array_any($blocks, static fn(array $block): bool => $line >= $block[0] && $line <= $block[1])) {
                continue;
            }

            if (trim(ltrim(trim($lines[$line - 1] ?? ''), '/*')) !== '') {
                return true;
            }
        }

        return false;
    }
}
