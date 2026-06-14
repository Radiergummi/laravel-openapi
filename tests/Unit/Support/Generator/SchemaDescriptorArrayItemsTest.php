<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Attributes\ResponseField;
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

it('keeps array constraints on the inner branch for a nullable array field attribute (#279)', function (): void {
    // End-to-end through the attribute path: ResponseField -> SchemaDescriptor -> toSchema().
    $field = new ResponseField(type: 'array', nullable: true, minItems: 1, maxItems: 10);
    $schema = $field->descriptor()->toSchema();

    expect($schema->type)->toBe(Generator::UNDEFINED)
        ->and($schema->minItems)->toBe(Generator::UNDEFINED)
        ->and($schema->maxItems)->toBe(Generator::UNDEFINED)
        ->and($schema->oneOf)->toHaveCount(2);

    $arrayBranch = collect($schema->oneOf)->first(fn($s) => $s->type === 'array');

    expect($arrayBranch?->minItems)->toBe(1)
        ->and($arrayBranch?->maxItems)->toBe(10);
});
