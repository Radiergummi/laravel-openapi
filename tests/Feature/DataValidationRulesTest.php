<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Radiergummi\OpenApi\Tests\Fixtures\ValidationRulesFixtureController;

uses()->group('openapi');

// region Minimal fixture controllers wired per test

/**
 * Returns properties by name from a component schema array (from parsed YAML).
 *
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function schemaProperties(array $spec, string $schemaName): array
{
    return $spec['components']['schemas'][$schemaName]['properties'] ?? [];
}

/**
 * Returns the required list for a component schema.
 *
 * @param array<string, mixed> $spec
 *
 * @return list<string>
 */
function schemaRequired(array $spec, string $schemaName): array
{
    return $spec['components']['schemas'][$schemaName]['required'] ?? [];
}

// endregion

// region Helpers to generate a spec with a single POST route using a Data class

beforeEach(function (): void {
    Log::spy();

    Route::post('/oa-fixture/validation-rules', [ValidationRulesFixtureController::class, 'store'])
        ->middleware('auth:api');

    Route::post('/oa-fixture/throwing-rules', [ValidationRulesFixtureController::class, 'storeThrowingRules'])
        ->middleware('auth:api');

    Route::post('/oa-fixture/tags', [ValidationRulesFixtureController::class, 'storeTags'])
        ->middleware('auth:api');

    $this->spec = generateSpec();
});

// endregion

// region name: maxLength from max:250

it('applies maxLength=250 to the name property from max:250 rule', function (): void {
    $props = schemaProperties($this->spec, 'ValidationRulesFixtureData');

    expect($props['name']['maxLength'])->toBe(250)
        ->and($props['name']['type'])->toBe('string');
});

it('marks name as required (no default, not Optional)', function (): void {
    $required = schemaRequired($this->spec, 'ValidationRulesFixtureData');

    expect($required)->toContain('name');
});

// endregion

// region description: PATCH-optional (Optional union) — rule required must NOT win

it('does NOT mark description as required despite being in rules payload', function (): void {
    $required = schemaRequired($this->spec, 'ValidationRulesFixtureData');

    expect($required)->not->toContain('description');
});

// endregion

// region score: minimum/maximum from integer + min:0 + max:100

it('applies minimum=0 and maximum=100 to the score property', function (): void {
    $props = schemaProperties($this->spec, 'ValidationRulesFixtureData');

    expect($props['score']['minimum'])->toBe(0)
        ->and($props['score']['maximum'])->toBe(100)
        ->and($props['score']['type'])->toBe('integer');
});

it('does NOT set minLength/maxLength on the integer score property', function (): void {
    $props = schemaProperties($this->spec, 'ValidationRulesFixtureData');

    expect($props['score'])->not->toHaveKey('minLength')
        ->and($props['score'])->not->toHaveKey('maxLength');
});

// endregion

// region email: format=email

it('applies format=email to the email property', function (): void {
    $props = schemaProperties($this->spec, 'ValidationRulesFixtureData');

    expect($props['email']['format'])->toBe('email')
        ->and($props['email']['type'])->toBe('string');
});

// endregion

// region tags: array type; tags.* items flow through (OAPI-016)

it('emits type=array for the tags property', function (): void {
    $props = schemaProperties($this->spec, 'ValidationRulesFixtureData');

    expect($props['tags']['type'])->toBe('array');
});

// endregion

// region status: enum from Spatie #[In] attribute

it('applies enum values from the Spatie In attribute on status', function (): void {
    $props = schemaProperties($this->spec, 'ValidationRulesFixtureData');

    expect($props['status']['enum'])->toBe(['draft', 'published']);
});

// endregion

// region address: nested Data — its rules flow into AddressFixtureData schema

it('registers AddressFixtureData as a component schema', function (): void {
    expect($this->spec['components']['schemas'])->toHaveKey('AddressFixtureData');
});

it('applies maxLength=200 to street in the AddressFixtureData schema', function (): void {
    $props = schemaProperties($this->spec, 'AddressFixtureData');

    expect($props['street']['maxLength'])->toBe(200);
});

it('applies maxLength=100 to city in the AddressFixtureData schema', function (): void {
    $props = schemaProperties($this->spec, 'AddressFixtureData');

    expect($props['city']['maxLength'])->toBe(100);
});

it('marks street and city as required in AddressFixtureData', function (): void {
    $required = schemaRequired($this->spec, 'AddressFixtureData');

    expect($required)->toContain('street')
        ->and($required)->toContain('city');
});

it('does NOT mark zip as required in AddressFixtureData (has default null)', function (): void {
    $required = schemaRequired($this->spec, 'AddressFixtureData');

    expect($required)->not->toContain('zip');
});

// endregion

// region OAPI-003: required + nullable must keep field in required AND nullable

it('keeps notes in required[] AND marks it nullable via OAS 3.1 type array (OAPI-003 required+nullable)', function (): void {
    $props    = schemaProperties($this->spec, 'ValidationRulesFixtureData');
    $required = schemaRequired($this->spec, 'ValidationRulesFixtureData');

    // OAS 3.1: nullable is expressed as type: ['string', 'null'] instead of nullable: true.
    expect($required)->toContain('notes')
        ->and($props['notes']['type'])->toBe(['string', 'null']);
});

// endregion

// region Graceful fallback when rules() throws

it('still emits a schema for ThrowingRulesFixtureData even though rules() throws', function (): void {
    expect($this->spec['components']['schemas'])->toHaveKey('ThrowingRulesFixtureData');

    $props = schemaProperties($this->spec, 'ThrowingRulesFixtureData');

    // The type-derived schema must still be present.
    expect($props)->toHaveKey('name')
        ->and($props['name']['type'])->toBe('string');

    // @phpstan-ignore staticMethod.notFound (Mockery's facade spy macros aren't visible to PHPStan)
    Log::shouldHaveReceived('warning')
        ->with(Mockery::pattern('/Skipping validation rule extraction for .*ThrowingRulesFixtureData/'));
});

// endregion

// region OAPI-016: foo.* rules populate the parent array property's items schema

it('populates tags items with type=string from tags.* rules (OAPI-016)', function (): void {
    $props = schemaProperties($this->spec, 'TagsWithItemsFixtureData');

    expect($props['tags']['type'])->toBe('array')
        ->and($props['tags']['items']['type'])->toBe('string');
});

it('populates tags items with maxLength from tags.* max:10 rule (OAPI-016)', function (): void {
    $props = schemaProperties($this->spec, 'TagsWithItemsFixtureData');

    expect($props['tags']['items']['maxLength'])->toBe(10);
});

// endregion
