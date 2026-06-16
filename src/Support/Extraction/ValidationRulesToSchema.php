<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use BackedEnum;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;
use Radiergummi\OpenApi\Contracts\Extraction\SelfDocumentingRule;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use ReflectionException;
use ReflectionObject;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_pad;
use function array_unique;
use function array_values;
use function count;
use function enum_exists;
use function explode;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_getcsv;
use function str_replace;
use function strpbrk;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const PHP_EOL;
use const SORT_REGULAR;

/**
 * Maps Laravel validation rules to JSON Schema field descriptors.
 *
 * Accepts the raw `rules()` output (pipe-string (`'required|string|max:250'`) or array
 * form (`['required', 'string', Rule::in([…]]`)) and produces one {@see FieldDescriptor} per
 * field. Unknown rule objects emit a {@see Finding} with rule ID `rule.unknown` instead of being
 * silently dropped.
 *
 * @internal
 */
#[Scoped]
final readonly class ValidationRulesToSchema
{
    public function __construct(
        private FindingsCollector $findings = new ArrayFindingsCollector(),
        private ?JsonSchemaFromType $schemaFromType = null,
    ) {}

    /**
     * Collapses numeric path segments (`tags.0` → `tags.*`, `items.0.price` → `items.*.price`).
     *
     * The Spatie `DataValidationRulesResolver` emits concrete indices because it iterates over the
     * synthetic payload; schema generation needs wildcard paths. When two normalized paths collide,
     * their rule lists are merged. Idempotent; already-wildcarded paths pass through unchanged.
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

            // Collision: merge both rule lists (unique, re-indexed).
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
     * Normalizes the given rules into a format suitable for {@see self::process()}.
     *
     * Dotted keys (`address.city`) and wildcard keys (`items.*.name`) are assembled into nested
     * object/array descriptors of arbitrary depth: a plain segment becomes a nested object
     * property, a `*` segment an array element. A bare `*` key maps to `additionalProperties`.
     *
     * @param array<string, array<int, mixed>|string> $rules
     *
     * @return array{
     *     fields: array<string, FieldDescriptor>,
     *     additionalPropertiesField: ?FieldDescriptor
     * }
     */
    public function process(array $rules, ?string $sourceClass = null): array
    {
        /** @var array<string, RuleTreeNode> $rootChildren */
        $rootChildren = [];
        $additionalPropertiesField = null;

        foreach ($rules as $field => $fieldRules) {
            // A field's ruleset may be a bare Rule object (`'x' => new SomeRule`) rather than a
            // pipe-string or array; Laravel permits this. Wrap it so it flows through the same
            // path as an in-array rule object; an un-introspectable object then yields a bare
            // descriptor (and a `rule.unknown` finding) instead of a TypeError.
            if (!is_string($fieldRules) && !is_array($fieldRules)) {
                $fieldRules = [$fieldRules];
            }

            // A bare `*` rule key applies to every value in the request body; model this as JSON
            // Schema's `additionalProperties` rather than emitting a property literally named
            // `*` (which is OAS-meaningless and trips downstream lint rules).
            if ($field === '*') {
                $additionalPropertiesField = $this->mapRules(
                    $this->normalizeRules($fieldRules),
                    $field,
                    $sourceClass,
                );

                continue;
            }

            $this->insertPath($rootChildren, explode('.', $field), $fieldRules);
        }

        $fields = [];

        foreach ($rootChildren as $name => $node) {
            $fields[$name] = $this->nodeToDescriptor($node, $name, $sourceClass);
        }

        return [
            'fields' => $fields,
            'additionalPropertiesField' => $additionalPropertiesField,
        ];
    }

    /**
     * Maps a normalized list of rules to a single {@see FieldDescriptor}.
     *
     * Quantifier rules (`min`, `max`, `between`, `size`) are deferred so `type` is resolved first;
     * otherwise `['min:1', 'array']` would set `minLength` instead of `minItems`.
     *
     * @param list<object|string> $rules
     */
    private function mapRules(
        array $rules,
        string $field = '',
        ?string $sourceClass = null,
    ): FieldDescriptor {
        $descriptor = new FieldDescriptor();

        /** @var list<array{name: string, arg: string}> $deferred */
        $deferred = [];

        foreach ($rules as $rule) {
            if (is_object($rule)) {
                $this->applyObjectRule(
                    $rule,
                    $descriptor,
                    $field,
                    $sourceClass,
                );

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
     * `In`, `Enum`, and `Dimensions` are `Stringable`; their `__toString()` is re-dispatched
     * through the string-rule path to avoid reflecting into protected state.
     *
     * `File` exposes no public accessor; only the `instanceof` marker is consumed. Unknown rule
     * objects emit a {@see Finding} so gaps are visible rather than silent.
     */
    private function applyObjectRule(
        object $rule,
        FieldDescriptor $field,
        string $propertyName = '',
        ?string $sourceClass = null,
    ): void {
        // A `Rule::enum()` over a backed enum's full case set resolves to the shared reusable enum
        // component. only()/except() subsets and unit enums fall through to the inline value list.
        if ($rule instanceof Enum) {
            $reference = $this->backedEnumComponentReference($rule);

            if ($reference !== null) {
                $field->ref = $reference;

                return;
            }
        }

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

        if ($rule instanceof Email) {
            $this->applyEmailRule($rule, $field);

            return;
        }

        if ($rule instanceof Exists) {
            $this->applyExistsRule($rule, $field);

            return;
        }

        if ($rule instanceof Unique) {
            $this->applyUniqueRule($rule, $field);

            return;
        }

        if ($rule instanceof NotIn) {
            $this->applyNotInRule($rule, $field);

            return;
        }

        if ($rule instanceof File) {
            $this->applyFile($field);

            return;
        }

        if ($rule instanceof SelfDocumentingRule) {
            $this->applySelfDocumentingRule($rule, $field, $sourceClass);

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
                    Finding::CONTEXT_SOURCE_CLASS => $sourceClass,
                ],
            ),
        );
    }

    /**
     * Returns the shared component `$ref` for a `Rule::enum()` that covers the full case set.
     * Returns null for unit enums, `only()`/`except()` subsets, or when no
     * {@see JsonSchemaFromType} is wired; the caller falls back to the inline value list.
     */
    private function backedEnumComponentReference(Enum $rule): ?string
    {
        if ($this->schemaFromType === null) {
            return null;
        }

        $reflection = new ReflectionObject($rule);

        try {
            $enumClass = $reflection->getProperty('type')->getValue($rule);

            // only()/except() narrow the rule to a subset of cases, not the full shared component.
            $only = $reflection->getProperty('only')->getValue($rule);
            $except = $reflection->getProperty('except')->getValue($rule);
        } catch (ReflectionException) {
            // The rule's internals are not shaped as expected; fall back to the inline value list.
            return null;
        }

        if (!is_string($enumClass)
            || !enum_exists($enumClass)
            || !is_a($enumClass, BackedEnum::class, allow_string: true)
        ) {
            return null;
        }

        if ($only !== [] || $except !== []) {
            return null;
        }

        /** @var class-string<BackedEnum> $enumClass */
        return $this->schemaFromType->backedEnumComponentReference($enumClass);
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
            'file', 'image', 'mimes', 'mimetypes' => $this->applyFile($field),
            'email' => $this->applyEmail($field),
            'url', 'active_url' => $this->applyUrl($field),
            'uuid' => $this->applyUuid($field),
            'multiple_of' => $this->applyMultipleOf($field, $arg),
            'mac_address' => $this->applyMacAddress($field),
            'hex_color' => $this->applyHexColor($field),
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

        // required_* conditional variants do NOT set required=true.
    }

    /**
     * `present` requires the key in the input but says nothing about nullability.
     */
    private function applyPresent(FieldDescriptor $field): void
    {
        $field->required = true;
    }

    /**
     * Marks the field as nullable without touching the required state.
     * `nullable` alone does not allow the key to be omitted; only `sometimes` does.
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

    /**
     * Maps `multiple_of:N` to JSON Schema's `multipleOf`, preserving int vs. float kind.
     * The `type` is left to an explicit `integer`/`numeric` rule.
     */
    private function applyMultipleOf(FieldDescriptor $field, string $arg): void
    {
        if ($arg === '') {
            return;
        }

        $field->multipleOf = preg_match('/^-?\d+$/', $arg) === 1
            ? (int) $arg
            : (float) $arg;
    }

    /**
     * Maps `mac_address` to a pattern (JSON Schema has no MAC-address `format`).
     * Accepts both colon- and hyphen-separated six-octet forms.
     */
    private function applyMacAddress(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->pattern = '^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$';
    }

    /**
     * Maps `hex_color` to a pattern (no standard `format`); matches six-digit hex with optional `#`.
     */
    private function applyHexColor(FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->pattern = '^#?[0-9a-fA-F]{6}$';
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
        // type: 'string' because `digits` accepts leading zeros (e.g., "007"), which are not valid
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
            // type: 'string'; see applyDigits() above.
            $field->type = 'string';
            $field->pattern = "^\\d{{$a},{$b}}$";
        }
    }

    /**
     * Strips the PCRE delimiter pair and flags, producing a raw ECMA pattern for OAS `pattern`.
     * Supports any non-alphanumeric delimiter; silently drops the rule on parse failure.
     */
    private function applyRegex(FieldDescriptor $field, string $raw): void
    {
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

        $inner = substr($pattern, 1, $closingPos - 1);

        if ($inner === '') {
            return;
        }

        $field->pattern = $inner;
    }

    /**
     * Handles the `in:` rule (hand-written CSV or `In`/`Enum::__toString()` re-dispatched from
     * {@see applyObjectRule()}). `str_getcsv` parses both plain and RFC-4180 quoted forms.
     * Numeric strings are coerced so int/float values survive the string round-trip.
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
     * Infers `type` from a homogeneous enum value set when no explicit type rule has set it.
     * Mixed-type sets leave `type` unset.
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
     * Classifies a PHP date-format string into an OAS `format` (`date`, `time`, or `date-time`).
     * Detects date tokens (`Yymndj`) and time tokens (`HGhgisuv`); defaults to `date-time`.
     * Escaped characters are not skipped intentionally (`Y-m-d\TH:i:sP` contains real time tokens).
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
     * Handles the `dimensions:` rule (no JSON Schema equivalent); marks the field as a binary
     * upload and surfaces pixel-dimension constraints as description text.
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
     * Builds a human-readable description from a `dimensions:` argument (`width=800,ratio=1.5`).
     * Known keys are mapped to plain English; unknown keys pass through verbatim.
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
            : sprintf('Image dimensions: %s.', implode(', ', $parts));
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
     * Extracts constraints from a `Password` rule. JSON Schema cannot enforce character-class or
     * breach checks, so those are surfaced as a `description` string. `appliedRules()` (Laravel 10+)
     * returns all flags without reflection.
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
            $field->description = sprintf(
                'Must contain: %s.',
                implode(', ', $requirements),
            );
        }
    }

    private function applyEmailRule(Email $rule, FieldDescriptor $field): void
    {
        $field->type = 'string';
        $field->format = 'email';

        if ($rule->validateMxRecord) {
            $field->description = $this->appendDescription(
                $field->description,
                'MX record will be validated.',
            );
        }
    }

    private function appendDescription(?string $existing, string $addition): string
    {
        return $existing === null || $existing === ''
            ? $addition
            : "{$existing} {$addition}";
    }

    private function applyExistsRule(Exists $rule, FieldDescriptor $field): void
    {
        [$table, $column] = $this->parseDatabaseRule((string) $rule);

        $field->description = $this->appendDescription(
            $field->description,
            $column === ''
                ? "Must reference an existing row in `{$table}`."
                : "Must reference an existing row in `{$table}.{$column}`.",
        );
    }

    /**
     * Parses the `<rule>:<table>,<column>` segment of a DatabaseRule's `__toString()`.
     *
     * @return array{0: string, 1: string}
     */
    private function parseDatabaseRule(string $serialised): array
    {
        $arg = $this->ruleArg($serialised);
        $parts = explode(',', $arg, 3);
        $table = $parts[0] ?? '';
        $column = $parts[1] ?? '';

        // Laravel emits `NULL` as a placeholder when no column was supplied.
        if ($column === 'NULL') {
            $column = '';
        }

        return [$table, $column];
    }

    private function applyUniqueRule(Unique $rule, FieldDescriptor $field): void
    {
        [$table, $column] = $this->parseDatabaseRule((string) $rule);

        $field->description = $this->appendDescription(
            $field->description,
            $column === ''
                ? "Must be unique in `{$table}`."
                : "Must be unique in `{$table}.{$column}`.",
        );
    }

    private function applyNotInRule(NotIn $rule, FieldDescriptor $field): void
    {
        $serialised = (string) $rule;
        $values = $this->ruleArg($serialised);

        // NotIn wraps each value in double-quotes; empty $escape avoids the PHP 8.4
        // backslash-escape deprecation.
        $parsed = array_map(
            static fn(?string $value): string
                => $value === null
                ? ''
                : str_replace('""', '"', trim($value, '"')),
            str_getcsv($values, ',', '"', ''),
        );

        $field->description = $this->appendDescription(
            $field->description,
            sprintf(
                'Must not be one of: %s.',
                implode(', ', $parsed),
            ),
        );
    }

    private function applySelfDocumentingRule(
        SelfDocumentingRule $rule,
        FieldDescriptor $field,
        ?string $sourceClass = null,
    ): void {
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
            // User code may ignore the PHPStan type; filter at runtime so non-scalars
            // don't reach swagger-php's YAML emitter with an opaque serialization error.
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
                        context: ['rule_class' => $rule::class, Finding::CONTEXT_SOURCE_CLASS => $sourceClass],
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

        if ($doc->example !== null && $field->example === null) {
            $field->example = $doc->example;
        }

        if ($doc->description !== null) {
            $field->description = $field->description === null
                ? $doc->description
                : $field->description . PHP_EOL . PHP_EOL . $doc->description;
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
        $min = (int) $arg;

        if ($field->type === 'integer' || $field->type === 'number') {
            $field->minimum = $min;
        } elseif ($field->type === 'array') {
            $field->minItems = $min;
        } else {
            $field->minLength = $min;
        }
    }

    private function applyMax(FieldDescriptor $field, string $arg): void
    {
        $max = (int) $arg;

        if ($field->type === 'integer' || $field->type === 'number') {
            $field->maximum = $max;
        } elseif ($field->type === 'array') {
            $field->maxItems = $max;
        } else {
            $field->maxLength = $max;
        }
    }

    private function applyBetween(FieldDescriptor $field, string $arg): void
    {
        $parts = explode(',', $arg, 2);

        if (count($parts) === 2) {
            [$min, $max] = $parts;

            $this->applyMin($field, trim($min));
            $this->applyMax($field, trim($max));
        }
    }

    private function applySize(FieldDescriptor $field, string $arg): void
    {
        $size = (int) $arg;

        if ($field->type === 'integer' || $field->type === 'number') {
            $field->minimum = $size;
            $field->maximum = $size;
        } elseif ($field->type === 'array') {
            $field->minItems = $size;
            $field->maxItems = $size;
        } else {
            $field->minLength = $size;
            $field->maxLength = $size;
        }
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
     * Walks a dotted/wildcard path into the {@see RuleTreeNode} tree, creating or reusing nodes
     * so sibling paths merge onto shared parents.
     *
     * @param array<string, RuleTreeNode> $children the current object level (by reference)
     * @param list<string>                $segments remaining path segments
     * @param array<int, mixed>|string    $rules    the path's raw rule list
     */
    private function insertPath(array &$children, array $segments, string|array $rules): void
    {
        $segment = array_shift($segments);

        if ($segment === null || $segment === '*') {
            return;
        }

        $node = $children[$segment] ??= new RuleTreeNode();

        if ($segments === []) {
            $node->ownRules = array_merge($node->ownRules, $this->normalizeRules($rules));

            return;
        }

        if ($segments[0] === '*') {
            array_shift($segments);
            $node->items ??= new RuleTreeNode();

            if ($segments === []) {
                // `foo.*`: the element itself carries these rules.
                $node->items->ownRules = array_merge(
                    $node->items->ownRules,
                    $this->normalizeRules($rules),
                );

                return;
            }

            // `foo.*.bar…`: the element is an object; recurse into its children.
            $this->insertPath($node->items->children, $segments, $rules);

            return;
        }

        $this->insertPath($node->children, $segments, $rules);
    }

    /**
     * Converts a {@see RuleTreeNode} into a {@see FieldDescriptor}. A node with children is an
     * `object`; a wildcard element forces `type = 'array'` even when a conflicting scalar rule
     * is also present on the same key.
     */
    private function nodeToDescriptor(RuleTreeNode $node, string $name, ?string $sourceClass): FieldDescriptor
    {
        $descriptor = $this->mapRules($node->ownRules, $name, $sourceClass);

        if ($node->children !== []) {
            $descriptor->type ??= 'object';

            $properties = [];

            foreach ($node->children as $childName => $childNode) {
                $properties[$childName] = $this->nodeToDescriptor($childNode, $childName, $sourceClass);
            }

            $descriptor->properties = $properties;
        }

        if ($node->items !== null) {
            $descriptor->type = 'array';
            $descriptor->items = $this->nodeToDescriptor($node->items, $name, $sourceClass);
        }

        return $descriptor;
    }
}
