<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Extractors\FieldDescriptor;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Password;

uses()->group('openapi');

/**
 * Inline fixture enums for the Rule::enum() coverage. Kept local — only this
 * test file consumes them, and they're trivial enough to avoid a separate
 * fixture file.
 */
enum IntBackedFixtureEnum: int
{
    case First  = 1;
    case Second = 2;
    case Third  = 3;
}

enum StringBackedFixtureEnum: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';
}

enum UnitFixtureEnum
{
    case Alpha;
    case Beta;
}

beforeEach(function (): void {
    $this->mapper = new ValidationRulesToSchema();
});

/**
 * Helper: process a single-field rules array and return its FieldDescriptor.
 *
 * @param array<int, mixed>|string $rules
 */
function field(ValidationRulesToSchema $mapper, string|array $rules): FieldDescriptor
{
    $result = $mapper->process(['field' => $rules]);

    return $result['fields']['field'];
}

// ---------------------------------------------------------------------------
// required / optional
// ---------------------------------------------------------------------------

it('sets required=true for the bare required rule', function (): void {
    $d = field($this->mapper, 'required');

    expect($d->required)->toBeTrue();
});

it('leaves required=null for required_with (conditional, no signal either way)', function (): void {
    $d = field($this->mapper, 'required_with:other');

    expect($d->required)->toBeNull();
});

it('leaves required=null for required_without', function (): void {
    $d = field($this->mapper, 'required_without:other');

    expect($d->required)->toBeNull();
});

it('leaves required=null for required_if', function (): void {
    $d = field($this->mapper, 'required_if:status,active');

    expect($d->required)->toBeNull();
});

it('sets nullable=true but does NOT touch required for nullable rule (OAPI-003)', function (): void {
    $d = field($this->mapper, 'nullable');

    expect($d->nullable)->toBeTrue()
        ->and($d->required)->toBeNull();
});

it('keeps required=true when nullable follows required (OAPI-003)', function (): void {
    $d = field($this->mapper, ['required', 'nullable']);

    expect($d->required)->toBeTrue()
        ->and($d->nullable)->toBeTrue();
});

it('keeps required=true when required follows nullable (OAPI-003, rule order)', function (): void {
    $d = field($this->mapper, ['nullable', 'required']);

    expect($d->required)->toBeTrue()
        ->and($d->nullable)->toBeTrue();
});

it('sets required=false for sometimes rule', function (): void {
    $d = field($this->mapper, 'sometimes');

    expect($d->required)->toBeFalse();
});

it('sets required=true but NOT nullable for present rule (OAPI-007)', function (): void {
    $d = field($this->mapper, 'present');

    expect($d->required)->toBeTrue()
        ->and($d->nullable)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Type mapping
// ---------------------------------------------------------------------------

it('maps string rule to type=string', function (): void {
    expect(field($this->mapper, 'string')->type)->toBe('string');
});

it('maps integer rule to type=integer', function (): void {
    expect(field($this->mapper, 'integer')->type)->toBe('integer');
});

it('maps int rule to type=integer', function (): void {
    expect(field($this->mapper, 'int')->type)->toBe('integer');
});

it('maps numeric rule to type=number', function (): void {
    expect(field($this->mapper, 'numeric')->type)->toBe('number');
});

it('maps decimal rule to type=number', function (): void {
    expect(field($this->mapper, 'decimal:2')->type)->toBe('number');
});

it('maps boolean rule to type=boolean', function (): void {
    expect(field($this->mapper, 'boolean')->type)->toBe('boolean');
});

it('maps bool rule to type=boolean', function (): void {
    expect(field($this->mapper, 'bool')->type)->toBe('boolean');
});

it('maps array rule to type=array', function (): void {
    expect(field($this->mapper, 'array')->type)->toBe('array');
});

// ---------------------------------------------------------------------------
// Format mapping
// ---------------------------------------------------------------------------

it('maps email rule to format=email', function (): void {
    $d = field($this->mapper, 'email');

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('email');
});

it('maps url rule to format=uri', function (): void {
    $d = field($this->mapper, 'url');

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('uri');
});

it('maps url:http,https rule to format=uri (ignores protocol list)', function (): void {
    $d = field($this->mapper, 'url:http,https');

    expect($d->format)->toBe('uri');
});

it('maps uuid rule to format=uuid', function (): void {
    $d = field($this->mapper, 'uuid');

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('uuid');
});

it('maps ip rule to format=ip', function (): void {
    $d = field($this->mapper, 'ip');

    expect($d->format)->toBe('ip');
});

it('maps ipv4 rule to format=ipv4', function (): void {
    $d = field($this->mapper, 'ipv4');

    expect($d->format)->toBe('ipv4');
});

it('maps ipv6 rule to format=ipv6', function (): void {
    $d = field($this->mapper, 'ipv6');

    expect($d->format)->toBe('ipv6');
});

it('maps date rule to format=date (OAPI-006)', function (): void {
    $d = field($this->mapper, 'date');

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('date');
});

it('maps date_format:Y-m-d to format=date (OAPI-006)', function (): void {
    $d = field($this->mapper, 'date_format:Y-m-d');

    expect($d->format)->toBe('date');
});

it('maps date_format:H:i:s to format=time (OAPI-006)', function (): void {
    $d = field($this->mapper, 'date_format:H:i:s');

    expect($d->format)->toBe('time');
});

it('maps date_format:Y-m-d H:i:s (datetime) to format=date-time (OAPI-006)', function (): void {
    $d = field($this->mapper, 'date_format:Y-m-d H:i:s');

    expect($d->format)->toBe('date-time');
});

it('maps ISO-8601 date_format to format=date-time (OAPI-006)', function (): void {
    $d = field($this->mapper, 'date_format:Y-m-d\TH:i:sP');

    expect($d->format)->toBe('date-time');
});

// ---------------------------------------------------------------------------
// File rules
// ---------------------------------------------------------------------------

it('maps file rule to type=string, format=binary, isFile=true', function (): void {
    $d = field($this->mapper, 'file');

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('binary')
        ->and($d->isFile)->toBeTrue();
});

it('maps image rule to type=string, format=binary, isFile=true', function (): void {
    $d = field($this->mapper, 'image');

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('binary')
        ->and($d->isFile)->toBeTrue();
});

// ---------------------------------------------------------------------------
// min / max — string context (default)
// ---------------------------------------------------------------------------

it('maps max:N on string type to maxLength', function (): void {
    $d = field($this->mapper, 'string|max:250');

    expect($d->maxLength)->toBe(250)
        ->and($d->maximum)->toBeNull();
});

it('maps min:N on string type to minLength', function (): void {
    $d = field($this->mapper, 'string|min:3');

    expect($d->minLength)->toBe(3);
});

it('maps max:N on integer type to maximum', function (): void {
    $d = field($this->mapper, 'integer|max:100');

    expect($d->maximum)->toBe(100)
        ->and($d->maxLength)->toBeNull();
});

it('maps min:N on numeric type to minimum', function (): void {
    $d = field($this->mapper, 'numeric|min:0');

    expect($d->minimum)->toBe(0);
});

it('maps min/max on array type to minItems/maxItems', function (): void {
    $d = field($this->mapper, 'array|min:1|max:5');

    expect($d->minItems)->toBe(1)
        ->and($d->maxItems)->toBe(5);
});

// ---------------------------------------------------------------------------
// between / size
// ---------------------------------------------------------------------------

it('maps between:a,b to min and max', function (): void {
    $d = field($this->mapper, 'string|between:5,20');

    expect($d->minLength)->toBe(5)
        ->and($d->maxLength)->toBe(20);
});

it('maps size:N to minLength=maxLength=N for strings', function (): void {
    $d = field($this->mapper, 'string|size:10');

    expect($d->minLength)->toBe(10)
        ->and($d->maxLength)->toBe(10);
});

// ---------------------------------------------------------------------------
// digits / digits_between
// ---------------------------------------------------------------------------

it('maps digits:N to type=string and pattern (Bug 4: digits allows leading zeros)', function (): void {
    $d = field($this->mapper, 'digits:6');

    expect($d->type)->toBe('string')
        ->and($d->pattern)->toBe('^\\d{6}$');
});

it('maps digits_between:a,b to type=string and pattern (Bug 4: digits allows leading zeros)', function (): void {
    $d = field($this->mapper, 'digits_between:4,8');

    expect($d->type)->toBe('string')
        ->and($d->pattern)->toBe('^\\d{4,8}$');
});

// ---------------------------------------------------------------------------
// regex
// ---------------------------------------------------------------------------

it('strips slash delimiters from regex rule', function (): void {
    $d = field($this->mapper, 'regex:/^[a-z]+$/i');

    expect($d->pattern)->toBe('^[a-z]+$');
});

it('strips brace delimiters from regex rule', function (): void {
    $d = field($this->mapper, 'regex:{^foo}');

    expect($d->pattern)->toBe('^foo');
});

it('silently drops an unparseable regex', function (): void {
    $d = field($this->mapper, 'regex:');

    expect($d->pattern)->toBeNull();
});

it('silently drops a bare regex rule with no colon (bug: strpos false cast to 0 caused substr corruption)', function (): void {
    // Rule string "regex" (no colon, no arg) makes strpos() return false.
    // (int) false === 0, so substr($raw, 0 + 1) = "egex" was used as the
    // pattern body instead of short-circuiting, emitting a garbage pattern.
    $d = field($this->mapper, 'regex');

    expect($d->pattern)->toBeNull();
});


// ---------------------------------------------------------------------------
// in
// ---------------------------------------------------------------------------

it('maps in:a,b,c to enum', function (): void {
    $d = field($this->mapper, 'in:draft,published,archived');

    expect($d->enum)->toBe(['draft', 'published', 'archived']);
});

it('maps Rule::in() object to enum', function (): void {
    $d = field($this->mapper, [new In(['foo', 'bar'])]);

    expect($d->enum)->toBe(['foo', 'bar']);
});

it('preserves comma-containing values in Rule::in() without splitting them (OAPI-009)', function (): void {
    $d = field($this->mapper, [new In(['a,b', 'c'])]);

    expect($d->enum)->toBe(['a,b', 'c']);
});

it('coerces numeric string values in in: rule to integers', function (): void {
    $d = field($this->mapper, 'in:20,50');

    expect($d->enum)->toBe([20, 50]);
});

it('keeps non-numeric string values in in: rule as strings', function (): void {
    $d = field($this->mapper, 'in:20,foo,50');

    expect($d->enum)->toBe([20, 'foo', 50]);
});

it('preserves integer values in Rule::in() object', function (): void {
    $d = field($this->mapper, [new In([20, 50])]);

    expect($d->enum)->toBe([20, 50]);
});

it('preserves float values in Rule::in() object', function (): void {
    $d = field($this->mapper, [new In([1.5, 2.5])]);

    expect($d->enum)->toBe([1.5, 2.5]);
});

it('preserves mixed int and string values in Rule::in() object', function (): void {
    $d = field($this->mapper, [new In([1, 'two', 3])]);

    expect($d->enum)->toBe([1, 'two', 3]);
});

it('coerces float values in the in: rule to floats', function (): void {
    $d = field($this->mapper, 'in:1.5,2.5');

    expect($d->enum)->toBe([1.5, 2.5]);
});

it('preserves comma- and quote-containing values from a Rule::in() object', function (): void {
    // In::__toString() emits RFC-4180 quoted CSV; str_getcsv must round-trip
    // embedded separators and escaped quotes without reflecting into internals.
    $d = field($this->mapper, [new In(['a,b', 'say "hi"', 'c'])]);

    expect($d->enum)->toBe(['a,b', 'say "hi"', 'c']);
});

// ---------------------------------------------------------------------------
// Type inference from in: / Rule::in() / Rule::enum() value sets
// ---------------------------------------------------------------------------

it('infers type=integer from an all-integer in: value set', function (): void {
    expect(field($this->mapper, 'in:20,50')->type)->toBe('integer');
});

it('infers type=number from a mixed int/float in: value set', function (): void {
    expect(field($this->mapper, 'in:1,2.5')->type)->toBe('number');
});

it('infers type=string from an all-string in: value set', function (): void {
    expect(field($this->mapper, 'in:draft,published')->type)->toBe('string');
});

it('leaves type unset for a mixed-type in: value set', function (): void {
    expect(field($this->mapper, 'in:20,foo')->type)->toBeNull();
});

it('does not override an explicit type rule with in: type inference', function (): void {
    expect(field($this->mapper, 'string|in:1,2,3')->type)->toBe('string');
});

// ---------------------------------------------------------------------------
// Rule::enum() — OAPI-002 / OAPI-005
// ---------------------------------------------------------------------------

it('maps Rule::enum() of a string-backed enum to type=string + enum values', function (): void {
    $d = field($this->mapper, [new Enum(StringBackedFixtureEnum::class)]);

    expect($d->type)->toBe('string')
        ->and($d->enum)->toBe(['draft', 'published', 'archived']);
});

it('maps Rule::enum() of an int-backed enum to type=integer + integer enum values (OAPI-005)', function (): void {
    $d = field($this->mapper, [new Enum(IntBackedFixtureEnum::class)]);

    expect($d->type)->toBe('integer')
        ->and($d->enum)->toBe([1, 2, 3]);
});

it('maps Rule::enum() of a plain UnitEnum to case names', function (): void {
    $d = field($this->mapper, [new Enum(UnitFixtureEnum::class)]);

    expect($d->type)->toBe('string')
        ->and($d->enum)->toBe(['Alpha', 'Beta']);
});

it('honors only() on Rule::enum()', function (): void {
    $rule = Rule::enum(StringBackedFixtureEnum::class)->only([StringBackedFixtureEnum::Draft]);
    $d    = field($this->mapper, [$rule]);

    expect($d->enum)->toBe(['draft']);
});

it('honors except() on Rule::enum()', function (): void {
    $rule = Rule::enum(StringBackedFixtureEnum::class)->except([StringBackedFixtureEnum::Archived]);
    $d    = field($this->mapper, [$rule]);

    expect($d->enum)->toBe(['draft', 'published']);
});

// ---------------------------------------------------------------------------
// Rule-order independence — OAPI-004
// ---------------------------------------------------------------------------

it('routes min/max to minItems/maxItems when array is declared AFTER (OAPI-004)', function (): void {
    $d = field($this->mapper, ['min:1', 'max:5', 'array']);

    expect($d->minItems)->toBe(1)
        ->and($d->maxItems)->toBe(5)
        ->and($d->minLength)->toBeNull()
        ->and($d->maxLength)->toBeNull();
});

it('routes min/max to minimum/maximum when integer is declared AFTER (OAPI-004)', function (): void {
    $d = field($this->mapper, ['min:0', 'max:100', 'integer']);

    expect($d->minimum)->toBe(0)
        ->and($d->maximum)->toBe(100)
        ->and($d->minLength)->toBeNull()
        ->and($d->maxLength)->toBeNull();
});

it('routes between to minItems/maxItems regardless of order', function (): void {
    $d = field($this->mapper, ['between:1,5', 'array']);

    expect($d->minItems)->toBe(1)
        ->and($d->maxItems)->toBe(5);
});

it('routes size to minItems=maxItems regardless of order', function (): void {
    $d = field($this->mapper, ['size:3', 'array']);

    expect($d->minItems)->toBe(3)
        ->and($d->maxItems)->toBe(3);
});

it('maps size:N on integer type to minimum=maximum=N (bug: was emitting minLength/maxLength)', function (): void {
    $d = field($this->mapper, 'integer|size:5');

    expect($d->minimum)->toBe(5)
        ->and($d->maximum)->toBe(5)
        ->and($d->minLength)->toBeNull()
        ->and($d->maxLength)->toBeNull();
});

it('maps size:N on numeric type to minimum=maximum=N (bug: was emitting minLength/maxLength)', function (): void {
    $d = field($this->mapper, 'numeric|size:10');

    expect($d->minimum)->toBe(10)
        ->and($d->maximum)->toBe(10)
        ->and($d->minLength)->toBeNull()
        ->and($d->maxLength)->toBeNull();
});

// ---------------------------------------------------------------------------
// String vs array form
// ---------------------------------------------------------------------------

it('accepts pipe-separated string form', function (): void {
    $d = field($this->mapper, 'required|string|max:100');

    expect($d->required)->toBeTrue()
        ->and($d->type)->toBe('string')
        ->and($d->maxLength)->toBe(100);
});

it('accepts array form with same results', function (): void {
    $d = field($this->mapper, ['required', 'string', 'max:100']);

    expect($d->required)->toBeTrue()
        ->and($d->type)->toBe('string')
        ->and($d->maxLength)->toBe(100);
});

// ---------------------------------------------------------------------------
// Dotted keys
// ---------------------------------------------------------------------------

it('skips dotted keys and sets hasDottedKeys=true', function (): void {
    $result = $this->mapper->process([
        'name'         => 'required|string',
        'items.*.price' => 'numeric',
    ]);

    expect($result['hasDottedKeys'])->toBeTrue()
        ->and($result['fields'])->toHaveKey('name')
        ->and($result['fields'])->not->toHaveKey('items.*.price');
});

it('leaves hasDottedKeys=false when no dotted rules present', function (): void {
    $result = $this->mapper->process(['name' => 'required|string']);

    expect($result['hasDottedKeys'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Unknown rules are ignored silently
// ---------------------------------------------------------------------------

it('ignores unknown rules without throwing', function (): void {
    $d = field($this->mapper, 'string|custom_rule|another_unknown:arg');

    expect($d->type)->toBe('string');
});

// ---------------------------------------------------------------------------
// normaliseIndexedPaths
// ---------------------------------------------------------------------------

it('normalises a single numeric segment to *', function (): void {
    $result = $this->mapper->normaliseIndexedPaths(['tags.0' => ['string']]);

    expect($result)->toHaveKey('tags.*')
        ->and($result)->not->toHaveKey('tags.0');
});

it('normalises a numeric segment in the middle of a path', function (): void {
    $result = $this->mapper->normaliseIndexedPaths(['items.0.price' => ['numeric']]);

    expect($result)->toHaveKey('items.*.price')
        ->and($result)->not->toHaveKey('items.0.price');
});

it('leaves non-numeric dotted paths unchanged', function (): void {
    $result = $this->mapper->normaliseIndexedPaths(['address.street' => ['string']]);

    expect($result)->toHaveKey('address.street');
});

it('leaves top-level keys unchanged', function (): void {
    $result = $this->mapper->normaliseIndexedPaths(['name' => ['required', 'string']]);

    expect($result)->toHaveKey('name');
});

it('merges colliding paths after normalisation', function (): void {
    // Two numeric indices normalise to the same wildcard path — rules are merged.
    $result = $this->mapper->normaliseIndexedPaths([
        'tags.0' => ['string'],
        'tags.1' => ['string', 'max:50'],
    ]);

    expect($result)->toHaveKey('tags.*')
        ->and($result['tags.*'])->toContain('string')
        ->and($result['tags.*'])->toContain('max:50');
});

it('is idempotent on already-wildcarded paths', function (): void {
    $input  = ['tags.*' => ['string', 'max:50']];
    $result = $this->mapper->normaliseIndexedPaths($input);

    expect($result)->toBe($input);
});

it('does not throw when a colliding path carries a Closure rule', function (): void {
    // A Closure is a valid Laravel rule form. Two numeric indices normalise to the
    // same wildcard — the merge must not throw a TypeError when $incoming is a Closure.
    $closure = static fn($attr, $val, $fail) => null;

    $result = $this->mapper->normaliseIndexedPaths([
        'tags.0' => ['string'],
        'tags.1' => $closure,
    ]);

    expect($result)->toHaveKey('tags.*');
});

// ---------------------------------------------------------------------------
// Password rule object (OAPI-030)
// ---------------------------------------------------------------------------

it('maps Password::min(8) to type=string, format=password, minLength=8', function (): void {
    $d = field($this->mapper, [Password::min(8)]);

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('password')
        ->and($d->minLength)->toBe(8)
        ->and($d->maxLength)->toBeNull();
});

it('maps Password::min(8)->max(64) to minLength=8 and maxLength=64', function (): void {
    $d = field($this->mapper, [Password::min(8)->max(64)]);

    expect($d->minLength)->toBe(8)
        ->and($d->maxLength)->toBe(64);
});

it('maps Password with letters() and numbers() to a description mentioning both', function (): void {
    $d = field($this->mapper, [Password::min(8)->letters()->numbers()]);

    expect($d->description)->toContain('letters')
        ->and($d->description)->toContain('numbers');
});

it('maps Password with mixedCase() to a description mentioning mixed case', function (): void {
    $d = field($this->mapper, [Password::min(8)->mixedCase()]);

    expect($d->description)->toContain('mixed case');
});

it('maps Password with symbols() to a description mentioning symbols', function (): void {
    $d = field($this->mapper, [Password::min(8)->symbols()]);

    expect($d->description)->toContain('symbols');
});

it('maps Password with uncompromised() to a description mentioning data breaches', function (): void {
    $d = field($this->mapper, [Password::min(8)->uncompromised()]);

    expect($d->description)->toContain('data breaches');
});

it('emits no description when Password has no character-class requirements', function (): void {
    $d = field($this->mapper, [Password::min(8)]);

    expect($d->description)->toBeNull();
});

// ---------------------------------------------------------------------------
// File / ImageFile rule object
//
// File and ImageFile expose no public accessor and no __toString(); their
// mimetype/size/dimension constraints live in protected state. Extraction
// consumes only the public `instanceof` marker (the field is a binary upload)
// and deliberately does NOT reflect into framework internals — so no
// description is produced for File-level constraints.
// ---------------------------------------------------------------------------

it('maps File::types([pdf,docx]) to type=string, format=binary, isFile=true', function (): void {
    $d = field($this->mapper, [File::types(['pdf', 'docx'])]);

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('binary')
        ->and($d->isFile)->toBeTrue();
});

it('does not extract a description from a File rule (no public accessor)', function (): void {
    $d = field($this->mapper, [File::types(['pdf', 'docx'])->max(1024)]);

    expect($d->description)->toBeNull();
});

it('maps a bare File rule to type=string, format=binary, isFile=true with no description', function (): void {
    $d = field($this->mapper, [new File()]);

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('binary')
        ->and($d->isFile)->toBeTrue()
        ->and($d->description)->toBeNull();
});

it('maps File::image() to type=string, format=binary, isFile=true', function (): void {
    $d = field($this->mapper, [File::image()]);

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('binary')
        ->and($d->isFile)->toBeTrue();
});

it('does not extract a description from an ImageFile rule with embedded dimensions', function (): void {
    $dims = (new Dimensions())->width(800)->height(600);
    $d    = field($this->mapper, [File::image()->dimensions($dims)]);

    expect($d->description)->toBeNull();
});

// ---------------------------------------------------------------------------
// Dimensions rule object standalone (OAPI-030)
// ---------------------------------------------------------------------------

it('maps standalone Dimensions rule to type=string, format=binary, isFile=true', function (): void {
    $d = field($this->mapper, [(new Dimensions())->width(100)->height(100)]);

    expect($d->type)->toBe('string')
        ->and($d->format)->toBe('binary')
        ->and($d->isFile)->toBeTrue();
});

it('maps standalone Dimensions rule to a description with dimension constraints', function (): void {
    $d = field($this->mapper, [(new Dimensions())->minWidth(200)->maxWidth(800)]);

    expect($d->description)->toContain('min width=200')
        ->and($d->description)->toContain('max width=800');
});

it('maps Dimensions::ratio() to a description mentioning aspect ratio', function (): void {
    $d = field($this->mapper, [(new Dimensions())->ratio(1.777)]);

    expect($d->description)->toContain('aspect ratio');
});
