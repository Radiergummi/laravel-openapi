<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Tests\Fixtures\UnitFixtureEnum;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\TypeIdentifier;

uses()->group('openapi');

it('describes a unit enum using only the short class name, not the FQCN', function (): void {
    $schema = new JsonSchemaFromType(new NullLogger())
        ->fromType(new EnumType(UnitFixtureEnum::class));

    expect($schema->description)
        ->toContain('UnitFixtureEnum')
        ->not->toContain('Tests\\Fixtures\\OpenApi\\UnitFixtureEnum');
});

// region Bug 1: NullableType must emit OAS 3.1 type array, not nullable: true

it('emits type: [string, null] for NullableType(string) instead of nullable: true (Bug 1)', function (): void {
    $type   = new NullableType(new BuiltinType(TypeIdentifier::STRING));
    $schema = new JsonSchemaFromType(new NullLogger())->fromType($type);

    expect($schema->type)->toBe(['string', 'null'])
        ->and($schema->nullable)->not->toBeTrue();
});

it('wraps a nullable object $ref in oneOf with a null sibling (Bug 1)', function (): void {
    // NullableType wrapping an object type that resolves to a $ref (e.g. DateTime → string/date-time,
    // but any BuiltinType works here to confirm the plain-type branch, not the $ref branch).
    $type   = new NullableType(new BuiltinType(TypeIdentifier::INT));
    $schema = new JsonSchemaFromType(new NullLogger())->fromType($type);

    expect($schema->type)->toBe(['integer', 'null']);
});

// endregion
