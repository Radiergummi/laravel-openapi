<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing;

use Illuminate\Support\Str;

use function array_filter;
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
 */
final readonly class DocCommentParser
{
    public function parse(string $comment): DocComment
    {
        $allLines = Str::of($comment)
            ->explode("\n")
            ->slice(1, -1)
            ->map(fn(string $line) => ltrim($line, ' *'))
            ->all();

        $lines = array_values(array_filter(
            $allLines,
            static fn(string $line): bool => !preg_match('/^\s*@/', $line),
        ));

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

        return new DocComment($summary ?: null, $description ?: null);
    }
}
