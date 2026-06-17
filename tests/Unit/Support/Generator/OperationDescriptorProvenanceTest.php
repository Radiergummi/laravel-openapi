<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Generator;

use Radiergummi\OpenApi\Support\Generator\OperationDescriptor;
use Radiergummi\OpenApi\Support\Provenance\FieldProvenance;

uses()->group('openapi');

function descriptorWithProvenance(array $provenance): OperationDescriptor
{
    return new OperationDescriptor(
        summary: 'List Flights',
        description: null,
        tags: ['Flights'],
        parameters: [],
        security: null,
        responses: [],
        requestBody: null,
        deprecated: false,
        operationId: 'flights.index',
        externalDocs: null,
        provenance: $provenance,
    );
}

it('never leaks provenance into the serialised operation array', function (): void {
    $provenance = [
        new FieldProvenance('summary', 'List Flights', 'ResourceConventionResolver', 'index → GET'),
    ];

    $withProvenance = descriptorWithProvenance($provenance);
    $withoutProvenance = descriptorWithProvenance([]);

    expect($withProvenance->toArray())
        ->not->toHaveKey('provenance')
        ->and($withProvenance->toArray())->toEqual($withoutProvenance->toArray());
});

it('carries provenance through withOperationId', function (): void {
    $provenance = [
        new FieldProvenance('status', '201', 'ResourceConventionResolver', 'store → POST'),
    ];

    $copy = descriptorWithProvenance($provenance)->withOperationId('renamed');

    expect($copy->provenance)->toBe($provenance)
        ->and($copy->operationId)->toBe('renamed');
});
