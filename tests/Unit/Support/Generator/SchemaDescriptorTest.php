<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Generator\SchemaDescriptor;

uses()->group('openapi');

/**
 * Serialize a Property/Schema the way the generator does, so assertions see the emitted spec
 * (where swagger-php re-adds the `x-` prefix), not the in-memory `$x` bag.
 *
 * @return array<string, mixed>
 */
function serializeAnnotation(OA\AbstractAnnotation $annotation): array
{
    return json_decode($annotation->toJson(), associative: true);
}

// region x-* vendor-extension passthrough

it('strips the x- prefix before storing so the serialized property emits x-foo once', function (): void {
    $descriptor = new SchemaDescriptor(type: 'string', x: ['x-foo' => 1]);
    $property = new OA\Property(['property' => 'id', '_context' => new Context()]);

    $descriptor->applyTo($property);
    $serialized = serializeAnnotation($property);

    expect($serialized)->toHaveKey('x-foo')
        ->and($serialized['x-foo'])->toBe(1)
        ->and($serialized)->not->toHaveKey('x-x-foo');
});

it('emits an x-* extension on a standalone schema via toSchema()', function (): void {
    $descriptor = new SchemaDescriptor(type: 'string', x: ['x-foo' => 'bar']);
    $serialized = serializeAnnotation($descriptor->toSchema());

    expect($serialized['x-foo'])->toBe('bar')
        ->and($serialized)->not->toHaveKey('x-x-foo');
});

it('emits multiple x-* extensions on one field', function (): void {
    $descriptor = new SchemaDescriptor(type: 'string', x: ['x-foo' => 1, 'x-bar' => 'two']);
    $property = new OA\Property(['property' => 'id', '_context' => new Context()]);

    $descriptor->applyTo($property);
    $serialized = serializeAnnotation($property);

    expect($serialized['x-foo'])->toBe(1)
        ->and($serialized['x-bar'])->toBe('two');
});

it('emits a nested array x-* value as-is', function (): void {
    $descriptor = new SchemaDescriptor(type: 'string', x: ['x-meta' => ['a' => 1, 'b' => [2, 3]]]);
    $property = new OA\Property(['property' => 'id', '_context' => new Context()]);

    $descriptor->applyTo($property);
    $serialized = serializeAnnotation($property);

    expect($serialized['x-meta'])->toBe(['a' => 1, 'b' => [2, 3]]);
});

it('does not double-strip an unprefixed key (still emits a single x- prefix)', function (): void {
    $descriptor = new SchemaDescriptor(type: 'string', x: ['foo' => 1]);
    $property = new OA\Property(['property' => 'id', '_context' => new Context()]);

    $descriptor->applyTo($property);
    $serialized = serializeAnnotation($property);

    // We only strip a leading x-; swagger-php re-adds exactly one on serialize, so a bare key and
    // an x--prefixed key converge on the same emitted x-foo (no x-x-foo either way).
    expect($serialized['x-foo'])->toBe(1)
        ->and($serialized)->not->toHaveKey('x-x-foo');
});

it('does not touch $x when no extensions are given', function (): void {
    $descriptor = new SchemaDescriptor(type: 'string');
    $property = new OA\Property(['property' => 'id', '_context' => new Context()]);

    $descriptor->applyTo($property);

    expect($property->x)->toBe(Generator::UNDEFINED);
});

// endregion
