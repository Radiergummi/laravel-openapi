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

it('extracts an Example: directive and strips it from the description', function (): void {
    $result = DescriptionDirectives::parse("The product price.\nExample: 1999");

    expect($result->cleanDescription)->toBe('The product price.')
        ->and($result->example)->toBe(1999);
});

it('coerces scalar examples by lexical shape', function (): void {
    expect(DescriptionDirectives::parse('Example: 42')->example)->toBe(42)
        ->and(DescriptionDirectives::parse('Example: 3.14')->example)->toBe(3.14)
        ->and(DescriptionDirectives::parse('Example: true')->example)->toBe(true)
        ->and(DescriptionDirectives::parse('Example: false')->example)->toBe(false)
        ->and(DescriptionDirectives::parse('Example: hello')->example)->toBe('hello');
});

it('honours No-example by suppressing any Example: directive', function (): void {
    $result = DescriptionDirectives::parse("Some description.\nExample: 99\nNo-example");

    expect($result->suppressExample)->toBeTrue()
        ->and($result->example)->toBeNull();
});

it('extracts an Enum: directive into a string list', function (): void {
    $result = DescriptionDirectives::parse("Status code.\nEnum: pending, active, archived");

    expect($result->enum)->toBe(['pending', 'active', 'archived']);
});

it('handles a null description', function (): void {
    $result = DescriptionDirectives::parse(null);

    expect($result->cleanDescription)->toBeNull()
        ->and($result->example)->toBeNull()
        ->and($result->enum)->toBeNull();
});

it('returns null cleanDescription when only directives remain', function (): void {
    $result = DescriptionDirectives::parse("Example: 1\nNo-example");

    expect($result->cleanDescription)->toBeNull();
});

it('uses the last Example: when multiple are present', function (): void {
    $result = DescriptionDirectives::parse("Example: 1\nExample: 2");

    expect($result->example)->toBe(2);
});

it('treats an empty Enum: directive as no directive', function (): void {
    $result = DescriptionDirectives::parse("Status code.\nEnum:");

    expect($result->enum)->toBeNull();
});

it('treats Enum: with only whitespace/empty entries as no directive', function (): void {
    $result = DescriptionDirectives::parse("Status code.\nEnum: , ,");

    expect($result->enum)->toBeNull();
});
