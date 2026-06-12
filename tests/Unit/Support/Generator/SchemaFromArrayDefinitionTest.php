<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Generator\SchemaFromArrayDefinition;

uses()->group('openapi');

it('builds nested properties and items as object graphs', function (): void {
    $schema = SchemaFromArrayDefinition::build([
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ]);

    expect($schema->properties)->toHaveCount(2)
        ->and($schema->properties[1]->items)->toBeInstanceOf(OA\Items::class)
        ->and($schema->properties[1]->items->type)->toBe('string');
});

it('guarantees an items object on every array-typed node without one', function (): void {
    // 'tags' => [] and heterogeneous lists legitimately produce an items-less array
    // definition; swagger-php validation rejects that shape on both supported majors.
    $schema = SchemaFromArrayDefinition::build([
        'type' => 'object',
        'properties' => [
            'tags' => ['type' => 'array'],
        ],
    ]);

    expect($schema->properties[0]->items)->toBeInstanceOf(OA\Items::class);
});

it('guarantees items on a top-level array definition', function (): void {
    $schema = SchemaFromArrayDefinition::build(['type' => 'array']);

    expect($schema->items)->toBeInstanceOf(OA\Items::class);
});

it('leaves non-array nodes without items', function (): void {
    $schema = SchemaFromArrayDefinition::build(['type' => 'string']);

    expect(Generator::isDefault($schema->items))->toBeTrue();
});
