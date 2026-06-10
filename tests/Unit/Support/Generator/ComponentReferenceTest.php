<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;

uses()->group('openapi');

// region pointer() — build

it('builds a schema pointer by default', function (): void {
    expect(ComponentReference::pointer('Foo'))->toBe('#/components/schemas/Foo');
});

it('builds a pointer for an explicit component type', function (): void {
    expect(ComponentReference::pointer('Bar', ComponentType::Responses))
        ->toBe('#/components/responses/Bar');
});

it('preserves namespaced keys verbatim', function (): void {
    expect(ComponentReference::pointer('Domain.Data.Foo'))
        ->toBe('#/components/schemas/Domain.Data.Foo');
});

// endregion

// region name() — schema-name parse

it('extracts the schema name from a schema ref', function (): void {
    expect(ComponentReference::name('#/components/schemas/Foo'))->toBe('Foo');
});

it('returns null for a non-schema component ref', function (): void {
    expect(ComponentReference::name('#/components/responses/Foo'))->toBeNull();
});

it('returns null when name() gets a non-component ref', function (): void {
    expect(ComponentReference::name('Foo'))->toBeNull()
        ->and(ComponentReference::name('https://example.com/schema.json#/Foo'))->toBeNull();
});

it('round-trips with pointer()', function (): void {
    expect(ComponentReference::name(ComponentReference::pointer('Foo')))->toBe('Foo');
});

// endregion

// region parse() — generic parse

it('parses a schema ref into type and name', function (): void {
    expect(ComponentReference::parse('#/components/schemas/Foo'))
        ->toBe(['type' => 'schemas', 'name' => 'Foo']);
});

it('parses a response ref into type and name', function (): void {
    expect(ComponentReference::parse('#/components/responses/NotFound'))
        ->toBe(['type' => 'responses', 'name' => 'NotFound']);
});

it('keeps nested name segments in the name part', function (): void {
    expect(ComponentReference::parse('#/components/schemas/Foo/bar'))
        ->toBe(['type' => 'schemas', 'name' => 'Foo/bar']);
});

it('returns null when parse() gets a non-component ref', function (): void {
    expect(ComponentReference::parse('Foo'))->toBeNull()
        ->and(ComponentReference::parse('#/definitions/Foo'))->toBeNull();
});

// endregion
