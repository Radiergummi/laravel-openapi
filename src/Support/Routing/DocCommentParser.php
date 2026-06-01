<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Illuminate\Support\Str;
use Radiergummi\OpenApi\Routing\DocComment;

use function array_filter;
use function array_key_exists;
use function array_values;
use function implode;
use function ltrim;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Basic documentation comment parser
 *
 * Parses a doc comment into a summary and description. The first paragraph will be interpreted
 * as the summary; everything after the first blank line as the description. `@`-prefixed lines are
 * dropped; `@throws` is handled separately by {@see ThrowsExtractor}.
 *
 * @internal
 */
final class DocCommentParser
{
    /** @var array<string, DocComment> */
    private array $cache = [];

    public function parse(string $comment): DocComment
    {
        if (array_key_exists($comment, $this->cache)) {
            return $this->cache[$comment];
        }

        $allLines = Str::of($comment)
            ->explode("\n")
            ->slice(1, -1)
            ->map(fn(string $line) => ltrim($line, ' *'))
            ->all();

        $lines = array_values(
            array_filter(
                $allLines,
                static fn(string $line): bool => !preg_match('/^\s*@/', $line),
            ),
        );

        /** @var list<string> $summaryLines */
        $summaryLines = [];
        /** @var list<string> $descriptionLines */
        $descriptionLines = [];
        $inDescription = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (!$inDescription) {
                if ($trimmed === '') {
                    if ($summaryLines !== []) {
                        $inDescription = true;
                    }

                    continue;
                }

                $summaryLines[] = $line;

                continue;
            }

            $descriptionLines[] = $line;
        }

        $summary = $summaryLines !== []
            ? trim(implode(' ', $summaryLines))
            : null;

        $description = $descriptionLines !== []
            ? trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $descriptionLines)) ?? '')
            : null;

        return $this->cache[$comment] = new DocComment($summary ?: null, $description ?: null);
    }
}
