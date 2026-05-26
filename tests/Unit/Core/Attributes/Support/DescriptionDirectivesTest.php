<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\Support\DescriptionDirectives;

uses()->group('attributes', 'openapi');

it('returns the original description when no directives present', function (): void {
    $result = DescriptionDirectives::parse('The product price in cents.');

    expect($result->cleanDescription)->toBe('The product price in cents.')
        ->and($result->example)->toBeNull()
        ->and($result->suppressExample)->toBeFalse()
        ->and($result->enum)->toBeNull();
});

it('extracts an @example directive and strips it from the description', function (): void {
    $result = DescriptionDirectives::parse("The product price.\n@example 1999");

    expect($result->cleanDescription)->toBe('The product price.')
        ->and($result->example)->toBe(1999);
});

it('coerces scalar examples by lexical shape', function (): void {
    expect(DescriptionDirectives::parse('@example 42')->example)->toBe(42)
        ->and(DescriptionDirectives::parse('@example 3.14')->example)->toBe(3.14)
        ->and(DescriptionDirectives::parse('@example true')->example)->toBe(true)
        ->and(DescriptionDirectives::parse('@example false')->example)->toBe(false)
        ->and(DescriptionDirectives::parse('@example hello')->example)->toBe('hello');
});

it('honours @no-example by suppressing any @example directive', function (): void {
    $result = DescriptionDirectives::parse("Some description.\n@example 99\n@no-example");

    expect($result->suppressExample)->toBeTrue()
        ->and($result->example)->toBeNull();
});

it('extracts an @enum directive and coerces tokens by lexical shape', function (): void {
    $result = DescriptionDirectives::parse("Status code.\n@enum pending, active, archived");

    expect($result->enum)->toBe(['pending', 'active', 'archived']);
});

it('coerces integer enum tokens to ints', function (): void {
    $result = DescriptionDirectives::parse("HTTP status.\n@enum 200, 404, 500");

    expect($result->enum)->toBe([200, 404, 500]);
});

it('handles a null description', function (): void {
    $result = DescriptionDirectives::parse(null);

    expect($result->cleanDescription)->toBeNull()
        ->and($result->example)->toBeNull()
        ->and($result->enum)->toBeNull();
});

it('returns null cleanDescription for a whitespace-only description', function (): void {
    $result = DescriptionDirectives::parse("   \n  \t  ");

    expect($result->cleanDescription)->toBeNull();
});

it('returns null cleanDescription when only directives remain', function (): void {
    $result = DescriptionDirectives::parse("@example 1\n@no-example");

    expect($result->cleanDescription)->toBeNull();
});

it('uses the last @example when multiple are present', function (): void {
    $result = DescriptionDirectives::parse("@example 1\n@example 2");

    expect($result->example)->toBe(2);
});

it('treats an empty @enum directive as no directive', function (): void {
    $result = DescriptionDirectives::parse("Status code.\n@enum");

    expect($result->enum)->toBeNull();
});

it('treats @enum with only whitespace/empty entries as no directive', function (): void {
    $result = DescriptionDirectives::parse("Status code.\n@enum , ,");

    expect($result->enum)->toBeNull();
});

it('does not parse prose containing the literal text "Enum:" or "Example:"', function (): void {
    $description = "Status field.\nEnum: see docs at /enums for the full list.";
    $result = DescriptionDirectives::parse($description);

    expect($result->cleanDescription)->toBe($description)
        ->and($result->enum)->toBeNull();
});
