<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Attributes;

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
 * Recognized directives, one per line, case-sensitive. The `@` prefix is deliberate — it makes
 * directive lines visibly distinct from prose so a line like `Enum: see docs at /enums` cannot be
 * confused for a directive.
 *
 * - `@example 42`           — declare the field's example value.
 * - `@no-example`           — suppress example generation entirely.
 * - `@enum a, b, c`         — declare the field's enum values. Tokens are coerced by lexical
 *                             shape (`1` → int, `1.5` → float, `true`/`false` → bool).
 *
 * Directives may appear anywhere in the description; their lines are stripped from the clean
 * output. Multiple `@example` / `@enum` lines: the *last* wins. `@no-example` always wins over
 * any `@example` directive (whether earlier or later in the description); an explicit `example:`
 * argument on the attribute beats both.
 *
 * @internal
 */
final readonly class DescriptionDirectives
{
    public static function parse(?string $description): ParsedDescription
    {
        if ($description === null || trim($description) === '') {
            return new ParsedDescription(cleanDescription: null);
        }

        $cleanLines = [];
        $example = null;
        $suppress = false;
        $enum = null;

        foreach (explode("\n", $description) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '@no-example') {
                $suppress = true;

                continue;
            }

            if (str_starts_with($trimmed, '@example ') || $trimmed === '@example') {
                $value = trim(substr($trimmed, 8));
                $example = $value === '' ? null : self::coerceScalar($value);

                continue;
            }

            if (str_starts_with($trimmed, '@enum ') || $trimmed === '@enum') {
                $values = self::splitList(trim(substr($trimmed, 5)));
                $enum = $values === [] ? null : $values;

                continue;
            }

            $cleanLines[] = $line;
        }

        $clean = trim(implode("\n", $cleanLines));

        return new ParsedDescription(
            cleanDescription: $clean === '' ? null : $clean,
            example: $suppress ? null : $example,
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
     * @return list<bool|float|int|string>
     */
    private static function splitList(string $raw): array
    {
        return array_values(
            array_map(
                self::coerceScalar(...),
                array_filter(
                    array_map('trim', explode(',', $raw)),
                    static fn(string $v): bool => $v !== '',
                ),
            ),
        );
    }
}
