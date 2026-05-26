<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes\Support;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function implode;
use function preg_match;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Parses inline documentation directives out of a field description string.
 *
 * Recognised directives, one per line, case-sensitive:
 *
 * - `Example: 42`           — declare the field's example value.
 * - `No-example`            — suppress example generation entirely.
 * - `Enum: a, b, c`         — declare the field's enum values.
 *
 * Directives may appear anywhere in the description; their lines are stripped from the clean
 * output. Multiple `Example:` / `Enum:` lines: the *last* wins. `No-example` always wins over
 * any `Example:` directive (whether earlier or later in the description).
 */
final readonly class DescriptionDirectives
{
    public static function parse(?string $description): ParsedDescription
    {
        if ($description === null || trim($description) === '') {
            return new ParsedDescription(cleanDescription: $description);
        }

        $cleanLines = [];
        $example = null;
        $exampleSet = false;
        $suppress = false;
        $enum = null;

        foreach (explode("\n", $description) as $line) {
            $trimmed = trim($line);

            if ($trimmed === 'No-example') {
                $suppress = true;

                continue;
            }

            if (str_starts_with($trimmed, 'Example:')) {
                $example = self::coerceScalar(trim(substr($trimmed, 8)));
                $exampleSet = true;

                continue;
            }

            if (str_starts_with($trimmed, 'Enum:')) {
                $enum = self::splitList(trim(substr($trimmed, 5)));

                continue;
            }

            $cleanLines[] = $line;
        }

        $clean = trim(implode("\n", $cleanLines));

        return new ParsedDescription(
            cleanDescription: $clean === '' ? null : $clean,
            example: $suppress ? null : ($exampleSet ? $example : null),
            suppressExample: $suppress,
            enum: $enum,
        );
    }

    private static function coerceScalar(string $raw): int|float|bool|string
    {
        if ($raw === 'true') {
            return true;
        }

        if ($raw === 'false') {
            return false;
        }

        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        if (preg_match('/^-?\d+\.\d+$/', $raw) === 1) {
            return (float) $raw;
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $raw): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $raw),
        ), static fn(string $v): bool => $v !== ''));
    }
}
