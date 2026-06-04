<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\SchemaDescriptor;

uses()->group('openapi');

it('emits permissive items for an array schema with no element type', function (): void {
    // type:'array' with no items previously produced an items-less schema that swagger-php
    // rejects, hard-failing generation (#115). It must always carry an items schema.
    $schema = (new SchemaDescriptor(type: 'array'))->toSchema();

    expect($schema->type)->toBe('array')
        ->and($schema->items)->toBeInstanceOf(OA\Items::class);
});

it('emits typed items when an element type is given', function (): void {
    $schema = (new SchemaDescriptor(type: 'array', items: 'string'))->toSchema();

    expect($schema->items)->toBeInstanceOf(OA\Items::class)
        ->and($schema->items->type)->toBe('string');
});

it('applies items onto a property for an array field', function (): void {
    $property = new OA\Property(['property' => 'tags']);
    (new SchemaDescriptor(type: 'array', items: 'string'))->applyTo($property);

    expect($property->items)->toBeInstanceOf(OA\Items::class)
        ->and($property->items->type)->toBe('string');
});

it('does not add items to a non-array schema', function (): void {
    $schema = (new SchemaDescriptor(type: 'string', items: 'string'))->toSchema();

    expect(\Radiergummi\OpenApi\is_undefined($schema->items))->toBeTrue();
});
