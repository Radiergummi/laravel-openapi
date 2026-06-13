<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\SchemaDescriptor;

use function Radiergummi\OpenApi\is_undefined;

uses()->group('openapi');

it('emits no type for an untyped descriptor (open schema)', function (): void {
    $schema = (new SchemaDescriptor())->toSchema();

    expect(is_undefined($schema->type))->toBeTrue();
});

it('preserves an explicit type', function (): void {
    $schema = (new SchemaDescriptor(type: 'integer'))->toSchema();

    expect($schema->type)->toBe('integer');
});

it('does not constrain an untyped nullable descriptor to string', function (): void {
    // #[QueryParam(name: 'per_page', nullable: true)] with no explicit type previously yielded
    // type: ['string', 'null']; it must not seed 'string'.
    $schema = (new SchemaDescriptor(nullable: true))->toSchema();

    expect($schema->type)->not->toBe(['string', 'null']);
});

it('widens an explicitly typed nullable descriptor to the [type, null] shape', function (): void {
    $schema = (new SchemaDescriptor(type: 'integer', nullable: true))->toSchema();

    expect($schema->type)->toBe(['integer', 'null']);
});

it('keeps other set fields on the schema', function (): void {
    $schema = (new SchemaDescriptor(type: 'string', format: 'uri', maxLength: 100))->toSchema();

    expect($schema->type)->toBe('string')
        ->and($schema->format)->toBe('uri')
        ->and($schema->maxLength)->toBe(100);
});

it('keeps a nullable schema usable as a swagger-php Schema instance', function (): void {
    $schema = (new SchemaDescriptor(nullable: true))->toSchema();

    expect($schema)->toBeInstanceOf(OA\Schema::class);
});
