<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Password;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;

use function array_key_exists;
use function array_merge;
use function array_pad;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function str_getcsv;
use function strpbrk;
use function strtolower;
use function substr;
use function substr_count;
use function trim;

/**
 * Maps Laravel validation rules to JSON Schema field descriptors.
 *
 * Accepts the raw `rules()` output — pipe-string (`'required|string|max:250'`) or array form
 * (`['required', 'string', Rule::in([…]]`) — and produces one {@see FieldDescriptor} per field.
 * Unknown rule objects emit a {@see Finding} with rule ID `rule.unknown` instead of being
 * silently dropped.
 */
#[Scoped]
final readonly class ValidationRulesToSchema
{
    public function __construct(
        private FindingsCollector $findings = new ArrayFindingsCollector(),
    ) {}

    /**
     * Collapses numeric path segments to `*` (`tags.0` → `tags.*`, `items.0.price` → `items.*.price`).
     *
     * The Spatie `DataValidationRulesResolver` emits concrete indices because it iterates over the
     * synthetic payload; schema generation needs wildcard paths. When two normalised paths collide,
     * their rule lists are merged. Idempotent — already-wildcarded paths pass through unchanged.
     *
     * @param array<string, array<int, mixed>|string> $rules
     *
     * @return array<string, array<int, mixed>|string>
     */
    public function normaliseIndexedPaths(array $rules): array
    {
        $out = [];

        foreach ($rules as $path => $fieldRules) {
            // Replace every numeric segment with *.
            $normalised = preg_replace('/\.(\d+)(?=\.|$)/', '.*', $path) ?? $path;

            if (!array_key_exists($normalised, $out)) {
                $out[$normalised] = $fieldRules;

                continue;
            }

            // Collision — merge both rule lists (unique, re-indexed).
            $existing = is_string($out[$normalised])
                ? explode('|', $out[$normalised])
                : (array) $out[$normalised];

            $incoming = is_string($fieldRules)
                ? explode('|', $fieldRules)
                : (array) $fieldRules;

            $out[$normalised] = array_values(
                array_unique(array_merge($existing, $incoming), SORT_REGULAR),
            );
        }

        return $out;
    }

    /**
     * @param array<string, array<int, mixed>|string> $rules
     *
     * @return array{fields: array<string, FieldDescriptor>, itemsFields: array<string, FieldDescriptor>,
     *                       hasDottedKeys: bool}
     */
    public function process(array $rules, ?string $sourceClass = null): array
    {
        $fields = [];
        $itemsFields = [];
        $hasDottedKeys = false;

        foreach ($rules as $field => $fieldRules) {
            if (!str_contains($field, '.')) {
                $normalized = $this->normalizeRules($fieldRules);
                $fields[$field] = $this->mapRules($normalized, $field, $sourceClass);

                continue;
            }

            $hasDottedKeys = true;

            // One level of nesting: `foo.*` rules populate the parent field's items.
            // Deeper paths (foo.*.bar) are not yet supported and are silently dropped.
            // Closures cannot be introspected and are skipped.
            if (
                (is_string($fieldRules) || is_array($fieldRules))
                && substr_count($field, '.') === 1
                && str_ends_with($field, '.*')
            ) {
                $parent = substr($field, 0, -2);
                $normalized = $this->normalizeRules($fieldRules);
                $itemsFields[$parent] = $this->mapRules($normalized, $parent, $sourceClass);
            }
        }

        return [
            'fields' => $fields,
            'itemsFields' => $itemsFields,
            'hasDottedKeys' => $hasDottedKeys,
        ];
    }

    /**
     * @param array<int, mixed>|string $rules
     *
     * @return list<object|string>
     */
    private function normalizeRules(string|array $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        $out = [];

        foreach ($rules as $rule) {
            if (is_string($rule) && str_contains($rule, '|')) {
                foreach (explode('|', $rule) as $part) {
                    $out[] = $part;
                }
            } else {
                $out[] = $rule;
            }
        }

        return $out;
    }

    /**
     * Maps a normalized list of rules to a single {@see FieldDescriptor}.
     *
     * Two-pass design: quantifier rules (`min`, `max`, `between`, `size`) are deferred until every
     * other rule has run, so the descriptor's `type` is resolved before they branch on it.
     * Otherwise `['min:1', 'array']` would erroneously set `minLength` instead of `minItems`.
     *
     * @param list<object|string> $rules
     */
    private function mapRules(array $rules, string $field = '', ?string $sourceClass = null): FieldDescriptor
    {
        $descriptor = new FieldDescriptor();

        /** @var list<array{name: string, arg: string}> $deferred */
        $deferred = [];

        foreach ($rules as $rule) {
            if (is_object($rule)) {
                $this->applyObjectRule($rule, $descriptor, $field, $sourceClass);

                continue;
            }

            if (!is_string($rule)) {
                continue;
            }

            $ruleName = strtolower($this->ruleName($rule));
            $ruleArg = $this->ruleArg($rule);

            if (in_array($ruleName, ['min', 'max', 'between', 'size'], strict: true)) {
                $deferred[] = ['name' => $ruleName, 'arg' => $ruleArg];

                continue;
            }

            $this->applyStringRule($ruleName, $ruleArg, $rule, $descriptor);
        }

        foreach ($deferred as $rule) {
            $this->applyQuantifierRule($rule['name'], $rule['arg'], $descriptor);
        }

        return $descriptor;
    }

    /**
     * Maps a Laravel Rule object to schema constraints using only public APIs.
     *
     * `In`, `Enum` and `Dimensions` implement `Stringable`; their `__toString()` output is the
     * documented serialisation Laravel itself feeds to the validator (`Enum::__toString()` even
     * resolves `only()`/`except()` for us). It is re-dispatched through the string-rule path
     * rather than reflecting into protected state, so a renamed internal property cannot silently
     * break extraction.
     *
     * `File`/`ImageFile` expose no public accessor and no `__toString()` — only the public
     * `instanceof` marker is consumed (the field is a binary upload); their size/mimetype/
     * dimension constraints are intentionally not read. Unknown rule objects emit a {@see Finding}
     * so the gap is visible rather than silent.
     */
    private function applyObjectRule(
        object $rule,
        FieldDescriptor $field,
        string $propertyName = '',
        ?string $sourceClass = null,
    ): void {
        if ($rule instanceof In || $rule instanceof Enum || $rule instanceof Dimensions) {
            $string = (string) $rule;

            $this->applyStringRule(
                strtolower($this->ruleName($string)),
                $this->ruleArg($string),
                $string,
                $field,
            );

            return;
        }

        if ($rule instanceof Password) {
            $this->applyPasswordRule($rule, $field);

            return;
        }

        if ($rule instanceof File) {
            $this->applyFile($field);

            return;
        }

        if ($rule instanceof SelfDocumentingRule) {
            $this->applySelfDocumentingRule($rule, $field);

            return;
        }

        $this->findings->emit(
            new Finding(
                ruleId: 'rule.unknown',
                level: 2,
                message: sprintf(
                    'Unknown Rule object %s on property %s — schema constraint dropped',
                    $rule::class,
                    $propertyName,
                ),
                fixHint: 'Register a transformSchema hook to inject the constraint, or extend ValidationRulesToSchema.',
                context: [
                    'rule_class' => $rule::class,
                    'property' => $propertyName,
                    'source_class' => $sourceClass,
                ],
            ),
        );
    }

    private function applySelfDocumentingRule(SelfDocumentingRule $rule, FieldDescriptor $field): void
    {
        $doc = $rule->documentation();

        if ($doc->type !== null && $field->type === null) {
            $field->type = $doc->type;
        }

        if ($doc->format !== null && $field->format === null) {
            $field->format = $doc->format;
        }

        if ($doc->pattern !== null && $field->pattern === null) {
            $field->pattern = $doc->pattern;
        }

        if ($doc->enum !== null && $field->enum === null) {
            // PHPStan-types `RuleDocumentation::$enum` as `list<float|int|string>|null`, but
            // user code can ignore PHPStan. Filter at runtime so non-scalars don't propagate to
            // swagger-php's YAML emitter (where they fail with an opaque serialisation error far
            // from the source).
            $sanitised = [];
            $rejected = false;

            foreach ($doc->enum as $value) {
                if (is_int($value) || is_float($value) || is_string($value)) {
                    $sanitised[] = $value;
                } else {
                    $rejected = true;
                }
            }

            if ($rejected) {
                $this->findings->emit(
                    new Finding(
                        ruleId: 'rule.invalid-enum-value',
                        level: 2,
                        message: sprintf(
                            'SelfDocumentingRule %s returned a non-scalar enum value — only int/float/string are allowed.',
                            $rule::class,
                        ),
                        fixHint: 'Return enum values as int|float|string from RuleDocumentation::$enum.',
                        context: ['rule_class' => $rule::class],
                    ),
                );
            }

            if ($sanitised !== []) {
                $field->enum = $sanitised;
            }
        }

        if ($doc->minLength !== null && $field->minLength === null) {
            $field->minLength = $doc->minLength;
        }

        if ($doc->maxLength !== null && $field->maxLength === null) {
            $field->maxLength = $doc->maxLength;
        }

        if ($doc->minimum !== null && $field->minimum === null) {
            $field->minimum = $doc->minimum;
        }

        if ($doc->maximum !== null && $field->maximum === null) {
            $field->maximum = $doc->maximum;
        }

        if ($doc->description !== null) {
            $field->description = $field->description === null
                ? $doc->description
                : $field->description . "\n\n" . $doc->description;
        }
    }

    private function applyStringRule(
        string $name,
        string $arg,
        string $raw,
        FieldDescriptor $field,
    ): void {
        match ($name) {
            'required' => $field->required = true,
            'sometimes' => $field->required = false,
            'present' => $this->applyPresent($field),
            'nullable' => $this->applyNullable($field),
            'string' => $field->type = 'string',
            'integer', 'int' => $field->type = 'integer',
            'numeric', 'decimal' => $field->type = 'number',
            'boolean', 'bool' => $field->type = 'boolean',
            'array' => $field->type = 'array',
            'file', 'image' => $this->applyFile($field),
            'email' => $this->applyEmail($field),
            'url' => $this->applyUrl($field),
            'uuid' => $this->applyUuid($field),
            'ip' => $this->applyIp($field),
            'ipv4' => $this->applyIpv4($field),
            'ipv6' => $this->applyIpv6($field),
            'date' => $this->applyDate($field),
            'digits' => $this->applyDigits($field, $arg),
            'digits_between' => $this->applyDigitsBetween($field, $arg),
            'regex' => $this->applyRegex($field, $raw),
            'in' => $this->applyIn($field, $arg),
            'date_format' => $this->applyDateFormat($field, $arg),
            'dimensions' => $this->applyDimensions($field, $arg),
            default => null, // silently ignore
        };

        // required_* variants (required_with, required_without, required_if, etc.)
        // are conditional — do NOT set required=true.
    }

    /**
     * `present` requires the key to be present in the input, but says nothing about nullability.
     * Express this as required only; nullable must come from an explicit `nullable` rule.
     */
    private function applyPresent(FieldDescriptor $field): void
    {
        $field->required = true;
    }

    /**
     * Marks the field as nullable. Does NOT touch the rules-derived required state — `nullable` in
     * isolation does not imply the field can be omitted from the payload (Laravel semantics:
     * `nullable` only says the value may be null, the key still must be present unless `sometimes`
     * or a default lets it through).
     */
    private function applyNullable(FieldDescriptor $field): void
    {
        $field->nullable = true;
    }

    private function applyFile(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'binary';
        $field->isFile = true;
    }

    private function applyEmail(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'email';
    }

    private function applyUrl(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'uri';
    }

    private function applyUuid(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'uuid';
    }

    private function applyIp(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'ip';
    }

    private function applyIpv4(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'ipv4';
    }

    private function applyIpv6(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'ipv6';
    }

    private function applyDate(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'date';
    }

    private function applyDigits(FieldDescriptor $field, string $arg): void
    {
        $n = (int) $arg;
        // type: 'string' because `digits` accepts leading zeros (e.g. "007"), which are not valid
        // JSON integers. `pattern` is a string-only keyword in JSON Schema.
        $field->type = 'string';
        $field->pattern = "^\\d{{$n}}$";
    }

    private function applyDigitsBetween(FieldDescriptor $field, string $arg): void
    {
        $parts = explode(',', $arg, 2);

        if (count($parts) === 2) {
            $a = (int) trim($parts[0]);
            $b = (int) trim($parts[1]);
            // type: 'string' — see applyDigits() above.
            $field->type = 'string';
            $field->pattern = "^\\d{{$a},{$b}}$";
        }
    }

    /**
     * Strips the PCRE delimiter pair and trailing flags, producing a raw ECMA
     * pattern suitable for OAS `pattern`.
     *
     * Laravel supports any non-alphanumeric delimiter; the most common is `/`.
     * On parse failure, the rule is silently dropped.
     */
    private function applyRegex(FieldDescriptor $field, string $raw): void
    {
        // The raw rule is `regex:/pattern/flags` or `regex:{pattern}flags`.
        $colonPos = strpos($raw, ':');

        if ($colonPos === false) {
            return;
        }

        $pattern = ltrim(substr($raw, $colonPos + 1));

        if ($pattern === '') {
            return;
        }

        $delimiter = $pattern[0];

        // Bracket-style delimiters.
        $closing = match ($delimiter) {
            '{' => '}',
            '(' => ')',
            '[' => ']',
            '<' => '>',
            default => $delimiter,
        };

        $closingPos = strrpos($pattern, $closing);

        if ($closingPos === false || $closingPos <= 0) {
            return;
        }

        // Strip flags after closing delimiter.
        $inner = substr($pattern, 1, $closingPos - 1);

        if ($inner === '') {
            return;
        }

        $field->pattern = $inner;
    }

    /**
     * Handles the `in:` rule — hand-written `in:a,b,c`, or `In`/`Enum::__toString()` re-dispatched
     * from {@see applyObjectRule()}.
     *
     * `In` and `Enum` serialise to RFC-4180 quoted CSV (`in:"a","b"`); hand-written rules use plain
     * CSV. {@see str_getcsv()} parses both and keeps comma-containing values intact — so the
     * comma-mangling that previously forced reflection no longer applies. Numeric strings are
     * coerced so int/float enum values survive the string round-trip, and the field `type` is
     * inferred from the value set when no explicit type rule established it.
     */
    private function applyIn(FieldDescriptor $field, string $arg): void
    {
        if ($arg === '') {
            return;
        }

        // escape: '' selects RFC-4180 doubled-quote handling and avoids the PHP 8.4 deprecation
        // of the legacy backslash-escape default.
        $parsed = str_getcsv($arg, ',', '"', '');

        $values = [];

        foreach ($parsed as $value) {
            if ($value === null) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $values[] = $this->coerceScalarValue($value);
        }

        if ($values === []) {
            return;
        }

        $field->enum = $values;
        $this->inferTypeFromEnum($field, $values);
    }

    /**
     * Coerces a parsed `in:` value: integer-looking strings become ints, other numeric strings
     * become floats, everything else stays a string.
     */
    private function coerceScalarValue(string $value): int|float|string
    {
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * Infers the field `type` from a homogeneous enum value set, but only when no explicit type
     * rule has already set it. A mixed-type set leaves `type` unset — JSON Schema enums need no
     * declared type.
     *
     * @param list<float|int|string> $values
     */
    private function inferTypeFromEnum(FieldDescriptor $field, array $values): void
    {
        if ($field->type !== null) {
            return;
        }

        $allInt = true;
        $allNumeric = true;
        $allString = true;

        foreach ($values as $value) {
            $allInt = $allInt && is_int($value);
            $allNumeric = $allNumeric && (is_int($value) || is_float($value));
            $allString = $allString && is_string($value);
        }

        $field->type = match (true) {
            $allInt => 'integer',
            $allNumeric => 'number',
            $allString => 'string',
            default => null,
        };
    }

    private function applyDateFormat(FieldDescriptor $field, string $arg): void
    {
        $field->type = 'string';
        $field->format = $this->formatFromPhpDateFormat($arg);
    }

    /**
     * Classifies a PHP date-format string into an OpenAPI `format` value.
     *
     * Detection logic:
     * - Time tokens (`H`, `G`, `h`, `g`, `i`, `s`, `u`, `v`) → has time component.
     * - Date tokens (`Y`, `y`, `m`, `n`, `d`, `j`) → has date component.
     * - Both present → `date-time`; only date → `date`; only time → `time`.
     * - No recognisable tokens (unlikely) → fall back to `date-time`.
     *
     * Escaped characters (preceded by `\`) are intentionally not skipped; format
     * strings like `Y-m-d\TH:i:sP` contain real time tokens after the literal `T`.
     */
    private function formatFromPhpDateFormat(string $format): string
    {
        $hasDate = $format !== '' && strpbrk($format, 'Yymndj') !== false;
        $hasTime = $format !== '' && strpbrk($format, 'HGhgisuv') !== false;

        if ($hasDate && !$hasTime) {
            return 'date';
        }

        if ($hasTime && !$hasDate) {
            return 'time';
        }

        return 'date-time';
    }

    /**
     * Handles the `dimensions:` rule — the string form, or `Dimensions::__toString()` re-dispatched
     * from {@see applyObjectRule()}.
     *
     * `Dimensions` has no JSON Schema equivalent — it constrains image pixel dimensions. The field
     * is treated as a binary upload and the constraints are surfaced as description text.
     */
    private function applyDimensions(FieldDescriptor $field, string $arg): void
    {
        $this->applyFile($field);

        $description = $this->dimensionsDescription($arg);

        if ($description !== '') {
            $field->description = $description;
        }
    }

    /**
     * Builds a human-readable description from a `dimensions:` rule argument
     * (`width=800,height=600,ratio=1.5`). Keys map to plain-English descriptions; unknown keys are
     * passed through verbatim so future Laravel additions are not silently swallowed.
     */
    private function dimensionsDescription(string $arg): string
    {
        $arg = trim($arg);

        if ($arg === '') {
            return '';
        }

        $labels = [
            'width' => 'width',
            'height' => 'height',
            'min_width' => 'min width',
            'max_width' => 'max width',
            'min_height' => 'min height',
            'max_height' => 'max height',
            'ratio' => 'aspect ratio',
            'min_ratio' => 'min aspect ratio',
            'max_ratio' => 'max aspect ratio',
        ];

        $parts = [];

        foreach (explode(',', $arg) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = trim($key);

            if ($key === '') {
                continue;
            }

            $label = $labels[$key] ?? $key;
            $parts[] = $label . '=' . trim($value);
        }

        return $parts === []
            ? ''
            : 'Image dimensions: ' . implode(', ', $parts) . '.';
    }

    private function ruleName(string $rule): string
    {
        $colon = strpos($rule, ':');

        return $colon === false ? $rule : substr($rule, 0, $colon);
    }

    private function ruleArg(string $rule): string
    {
        $colon = strpos($rule, ':');

        return $colon === false ? '' : substr($rule, $colon + 1);
    }

    /**
     * Extracts constraints from a `Password` rule object.
     *
     * JSON Schema has no built-in equivalents for character-class requirements (letters, mixed case,
     * numbers, symbols) or the HaveIBeenPwned check. We emit:
     * - `type: string` + `format: password` (signals "render as password input" to UI tooling).
     * - `minLength` from `Password::min(N)` (always present; defaults to 8 per Laravel).
     * - `maxLength` from `Password::max(N)` when set.
     * - `description` listing all active character-class requirements in plain English, so
     *   client-side documentation can surface them even though JSON Schema can't enforce them.
     *
     * `appliedRules()` is a public method added in Laravel 10 that returns the current state of
     * all flags, avoiding reflection entirely.
     */
    private function applyPasswordRule(Password $rule, FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'password';

        $applied = $rule->appliedRules();
        $field->minLength = (int) $applied['min'];

        if ($applied['max'] !== null) {
            $field->maxLength = (int) $applied['max'];
        }

        $requirements = [];

        if ($applied['letters']) {
            $requirements[] = 'letters';
        }

        if ($applied['mixedCase']) {
            $requirements[] = 'mixed case (uppercase and lowercase)';
        }

        if ($applied['numbers']) {
            $requirements[] = 'numbers';
        }

        if ($applied['symbols']) {
            $requirements[] = 'symbols';
        }

        if ($applied['uncompromised']) {
            $requirements[] = 'not present in known data breaches';
        }

        if ($requirements !== []) {
            $field->description = 'Must contain: ' . implode(', ', $requirements) . '.';
        }
    }

    private function applyQuantifierRule(string $name, string $arg, FieldDescriptor $field): void
    {
        match ($name) {
            'min' => $this->applyMin($field, $arg),
            'max' => $this->applyMax($field, $arg),
            'between' => $this->applyBetween($field, $arg),
            'size' => $this->applySize($field, $arg),
            default => null,
        };
    }

    private function applyMin(FieldDescriptor $field, string $arg): void
    {
        $n = (int) $arg;

        if ($field->type === 'integer' || $field->type === 'number') {
            $field->minimum = $n;
        } elseif ($field->type === 'array') {
            $field->minItems = $n;
        } else {
            $field->minLength = $n;
        }
    }

    private function applyMax(FieldDescriptor $field, string $arg): void
    {
        $n = (int) $arg;

        if ($field->type === 'integer' || $field->type === 'number') {
            $field->maximum = $n;
        } elseif ($field->type === 'array') {
            $field->maxItems = $n;
        } else {
            $field->maxLength = $n;
        }
    }

    private function applyBetween(FieldDescriptor $field, string $arg): void
    {
        $parts = explode(',', $arg, 2);

        if (count($parts) === 2) {
            $this->applyMin($field, trim($parts[0]));
            $this->applyMax($field, trim($parts[1]));
        }
    }

    private function applySize(FieldDescriptor $field, string $arg): void
    {
        $n = (int) $arg;

        if ($field->type === 'integer' || $field->type === 'number') {
            $field->minimum = $n;
            $field->maximum = $n;
        } elseif ($field->type === 'array') {
            $field->minItems = $n;
            $field->maxItems = $n;
        } else {
            $field->minLength = $n;
            $field->maxLength = $n;
        }
    }
}
