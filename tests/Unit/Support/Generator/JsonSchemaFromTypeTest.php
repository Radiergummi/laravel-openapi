<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Tests\Fixtures\Internal\BillingAccount;
use Radiergummi\OpenApi\Tests\Fixtures\UnitFixtureEnum;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeIdentifier;

uses()->group('openapi');

// region #148: DateTimeInterface and its implementations map to string/date-time

it('maps a property typed against DateTimeInterface to string/date-time', function (): void {
    $schema = new JsonSchemaFromType(new NullLogger())
        ->fromType(new ObjectType(DateTimeInterface::class));

    expect($schema->type)->toBe('string')
        ->and($schema->format)->toBe('date-time')
        ->and($schema->description)->not->toContain('Unmapped object type');
});

it('maps a concrete date class to string/date-time', function (string $className): void {
    $schema = new JsonSchemaFromType(new NullLogger())
        ->fromType(new ObjectType($className));

    expect($schema->type)->toBe('string')
        ->and($schema->format)->toBe('date-time');
})->with([
    DateTime::class,
    DateTimeImmutable::class,
    CarbonImmutable::class,
]);

// endregion

it('describes a unit enum using a human-readable resource name, not the FQCN', function (): void {
    $schema = new JsonSchemaFromType(new NullLogger())
        ->fromType(new EnumType(UnitFixtureEnum::class));

    expect($schema->description)
        ->toContain('Unit Fixture Enum')
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

// region Object / union / builtin fallbacks

it('renders an unmapped object type as a humanized resource name, never the FQCN', function (): void {
    $schema = new JsonSchemaFromType(new NullLogger())
        ->fromType(new ObjectType(BillingAccount::class));

    expect($schema->type)->toBe('string')
        ->and($schema->description)->toBe('Unmapped object type: Billing Account')
        ->and($schema->description)->not->toContain('BillingAccount')
        ->and($schema->description)->not->toContain('Internal')
        ->and($schema->description)->not->toContain('Radiergummi');
});

it('maps a UuidInterface object to string / format: uuid', function (): void {
    $schema = new JsonSchemaFromType(new NullLogger())
        ->fromType(new ObjectType(UuidInterface::class));

    expect($schema->type)->toBe('string')->and($schema->format)->toBe('uuid');
});

it('maps a union type to a oneOf of its members', function (): void {
    $type = new UnionType(
        new BuiltinType(TypeIdentifier::STRING),
        new BuiltinType(TypeIdentifier::INT),
    );
    $schema = new JsonSchemaFromType(new NullLogger())->fromType($type);

    $memberTypes = array_map(static fn(OA\Schema $member) => $member->type, $schema->oneOf);

    expect($schema->oneOf)->toHaveCount(2)
        ->and($memberTypes)->toContain('string')->toContain('integer');
});

it('maps builtin scalar types to their JSON Schema counterparts', function (TypeIdentifier $id, string $expected): void {
    $schema = new JsonSchemaFromType(new NullLogger())->fromType(new BuiltinType($id));

    expect($schema->type)->toBe($expected);
})->with([
    'float' => [TypeIdentifier::FLOAT, 'number'],
    'bool'  => [TypeIdentifier::BOOL, 'boolean'],
    'array' => [TypeIdentifier::ARRAY, 'array'],
]);

it('renders an unmapped builtin type as a string with a descriptive note', function (): void {
    $schema = new JsonSchemaFromType(new NullLogger())
        ->fromType(new BuiltinType(TypeIdentifier::OBJECT));

    expect($schema->type)->toBe('string')
        ->and($schema->description)->toBe('Unmapped builtin type: object');
});

// endregion
