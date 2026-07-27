<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\FormattedDateResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\FormattedDateShapeAResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\FormattedDateValueObjectResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\FormattedDateWithoutModelResource;

use function array_filter;
use function array_values;
use function str_contains;

uses()->group('openapi', 'plugin:api-resources');

class DateFormatInferenceController extends Controller
{
    public function formatted(): FormattedDateResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function withoutModel(): FormattedDateWithoutModelResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function valueObject(): FormattedDateValueObjectResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function shapeA(): FormattedDateShapeAResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

/**
 * The properties of the named resource component.
 *
 * @return array<string, mixed>
 */
function formattedDateProperties(string $route, string $action, string $component): array
{
    Route::get($route, [DateFormatInferenceController::class, $action]);

    $spec = generateSpec();

    expect($spec['components']['schemas'] ?? [])->toHaveKey($component);

    return $spec['components']['schemas'][$component]['properties'];
}

// region Recognised formats

it('types an RFC3339 ->format() on a date attribute as a date-time string', function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['atom_global'])->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        ->and($properties['atom_class_constant'])->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        ->and($properties['iso_c'])->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        // RFC3339 permits a fractional-second part, so the extended constant is date-time too.
        ->and($properties['rfc3339_extended'])->toMatchArray(['type' => 'string', 'format' => 'date-time']);
});

it('types a ->format() wrapped in a conditional and leaves the key optional', function (): void {
    Route::get('/dates', [DateFormatInferenceController::class, 'formatted']);

    $schema = generateSpec()['components']['schemas']['FormattedDateResource'];

    // The conditional wrapper resolves its value argument through the same step, so the refinement
    // composes; the key stays out of `required` because presence is runtime-decided.
    expect($schema['properties']['conditional_atom'])->toMatchArray([
        'type' => 'string',
        'format' => 'date-time',
    ])
        ->and($schema['required'])->not->toContain('conditional_atom');
});

it("types a 'Y-m-d' ->format() as a date string", function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['release_day'])->toMatchArray(['type' => 'string', 'format' => 'date'])
        // The argument decides the format, not the attribute: published_at is a datetime cast.
        ->and($properties['published_day'])->toMatchArray(['type' => 'string', 'format' => 'date'])
        // A `self::` constant on the resource resolves like an inline literal.
        ->and($properties['self_constant_day'])->toMatchArray(['type' => 'string', 'format' => 'date']);
});

it('keeps the model attribute description on the formatted property', function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['release_day']['description'])->toBe('The day the article goes on sale.');
});

// endregion

// region Formats that stay unrefined strings

it('leaves a non-RFC3339 format as a plain string', function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['space_separated'])->toBe(['type' => 'string'])
        // DATE_ISO8601 is PHP's legacy 'Y-m-d\TH:i:sO', which RFC3339 does not accept.
        ->and($properties['legacy_iso'])->toBe(['type' => 'string'])
        ->and($properties['expanded_iso'])->toBe(['type' => 'string']);
});

it('degrades a non-literal format argument to a plain string', function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['dynamic_format'])->toBe(['type' => 'string']);
});

// endregion

// region Nullability

it('drops the null member for a non-nullsafe ->format() and keeps the key required', function (): void {
    Route::get('/dates', [DateFormatInferenceController::class, 'formatted']);

    $schema = generateSpec()['components']['schemas']['FormattedDateResource'];

    // created_at is a nullable timestamp, but calling ->format() on it proves it was present.
    expect($schema['properties']['atom_global']['type'])->toBe('string')
        ->and($schema['required'])->toContain('atom_global');
});

it('keeps the nullable type for a nullsafe ?->format()', function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['nullsafe_atom'])->toMatchArray([
        'type' => ['string', 'null'],
        'format' => 'date-time',
    ]);
});

// endregion

// region Refusals

it('keeps ->format() on a non-date model attribute unconstrained', function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['formatted_string'])->toBe([])
        ->and($properties['formatted_unknown'])->toBe([])
        // A relation hop is not a wrapped-model attribute read.
        ->and($properties['formatted_relation'])->toBe([]);
});

it('keeps ->format() unconstrained when the resource wraps no model', function (): void {
    $properties = formattedDateProperties('/dates-modelless', 'withoutModel', 'FormattedDateWithoutModelResource');

    expect($properties['created_at'])->toBe([]);
});

// endregion

// region Value-object receivers

it('types a ->format() on a wrapped value object date property as a date-time string', function (): void {
    $properties = formattedDateProperties('/dates-value-object', 'valueObject', 'FormattedDateValueObjectResource');

    // The formatted key reads the same evidence the bare read does.
    expect($properties['issued_at'])->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        ->and($properties['issued_at_raw'])->toMatchArray(['type' => 'string', 'format' => 'date-time']);
});

it('types a ->format() on a typed value-object property of the resource', function (): void {
    $properties = formattedDateProperties('/dates-shape-a', 'shapeA', 'FormattedDateShapeAResource');

    expect($properties['starts_at'])->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        // The format argument decides the refinement, exactly as on the model path.
        ->and($properties['starts_day'])->toMatchArray(['type' => 'string', 'format' => 'date'])
        // A value-object property carries no prose for the key to inherit.
        ->and($properties['starts_at'])->not->toHaveKey('description');
});

it('reads nullability off the call, not the value-object property', function (): void {
    Route::get('/dates-shape-a', [DateFormatInferenceController::class, 'shapeA']);

    $schema = generateSpec()['components']['schemas']['FormattedDateShapeAResource'];

    // endsAt is nullable, but calling ->format() on it proves it was present.
    expect($schema['properties']['ends_at'])->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        ->and($schema['required'])->toContain('ends_at')
        ->and($schema['properties']['ends_at_nullsafe'])->toMatchArray([
            'type' => ['string', 'null'],
            'format' => 'date-time',
        ]);
});

it('degrades a non-literal format argument on a value-object receiver to a plain string', function (): void {
    $properties = formattedDateProperties('/dates-shape-a', 'shapeA', 'FormattedDateShapeAResource');

    expect($properties['dynamic_format'])->toBe(['type' => 'string']);
});

it('keeps ->format() on an untypeable value-object member unconstrained', function (): void {
    $properties = formattedDateProperties('/dates-shape-a', 'shapeA', 'FormattedDateShapeAResource');

    // MoneyValue is a plain object leaf the reader refuses outright, so no date evidence exists.
    expect($properties['price'])->toBe([]);
});

it('keeps ->format() on a typed but non-date value-object member unconstrained', function (): void {
    $properties = formattedDateProperties('/dates-shape-a', 'shapeA', 'FormattedDateShapeAResource');

    // The reader types a backed enum, so this reaches the date-evidence check and is refused there.
    expect($properties['currency'])->toBe([]);
});

// endregion

// region Untouched neighbours

it('leaves a bare model attribute read resolving as before', function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['raw_created_at'])->toMatchArray([
        'type' => ['string', 'null'],
        'format' => 'date-time',
    ]);
});

it('lets a #[ResourceField] win over the formatted property', function (): void {
    $properties = formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    expect($properties['declared_day'])->toMatchArray([
        'type' => 'integer',
        'description' => 'Declared, not inferred.',
    ]);
});

it('names only the still-unconstrained keys in the summarising generation-log note', function (): void {
    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    formattedDateProperties('/dates', 'formatted', 'FormattedDateResource');

    $notes = array_filter(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'FormattedDateResource'),
    );

    expect($notes)->toHaveCount(1);

    $note = array_values($notes)[0]['message'];

    expect($note)->toContain('formatted_string', 'formatted_unknown', 'formatted_relation')
        ->and($note)->not->toContain('atom_global')
        ->and($note)->not->toContain('nullsafe_atom');
});

// endregion
