<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Types;

use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\DescribedStatus;
use ReflectionClass;
use stdClass;
use Symfony\Component\TypeInfo\Type;

uses()->group('openapi');

/**
 * Structural + enum + leaf-callback coverage for the unified engine, porting the former
 * TypeNodeToSchema cases onto the symfony-Type path. Drives it exactly as production does:
 * {@see TypeNodeResolver::toType()} then {@see JsonSchemaFromType::fromType()}, with a sentinel
 * `$ref` leaf callback so leaf delegation is observable.
 *
 * @return array<string, mixed>
 */
function structuralSchema(string $expression): array
{
    $type = new TypeNodeResolver()->toType(
        parsePhpDocType($expression),
        new ReflectionClass(stdClass::class),
    );

    if ($type === null) {
        return ['__null__' => true];
    }

    $schema = new JsonSchemaFromType(new NullLogger(), new ComponentSchemaRegistry())->fromType(
        $type,
        static fn(string $fqcn): OA\Schema => new OA\Schema(['ref' => "#/components/schemas/{$fqcn}"]),
    );

    return json_decode(json_encode($schema), associative: true);
}

// region Array shapes

it('resolves an array shape into an object schema with required properties', function (): void {
    $schema = structuralSchema('array{foo: string, bar: int}');

    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['foo']['type'])->toBe('string')
        ->and($schema['properties']['bar']['type'])->toBe('integer')
        // Sorted key order (ksort), the pinned canonical from the characterization catalog.
        ->and($schema['required'])->toBe(['bar', 'foo']);
});

it('omits optional keys from required', function (): void {
    $schema = structuralSchema('array{name?: string, id: int}');

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['name', 'id'])
        ->and($schema['required'])->toBe(['id']);
});

it('emits no required key when every shape item is optional', function (): void {
    $schema = structuralSchema('array{name?: string}');

    expect($schema['type'])->toBe('object')
        ->and($schema)->not->toHaveKey('required');
});

it('resolves a nested array shape into a nested object', function (): void {
    $schema = structuralSchema('array{data: array{id: int}}');

    expect($schema['properties']['data']['type'])->toBe('object')
        ->and($schema['properties']['data']['properties']['id']['type'])->toBe('integer')
        ->and($schema['properties']['data']['required'])->toBe(['id']);
});

it('resolves an open array shape into additionalProperties', function (): void {
    $schema = structuralSchema('array{id: int, ...<string>}');

    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['id']['type'])->toBe('integer')
        ->and($schema['additionalProperties']['type'])->toBe('string');
});

// endregion

// region Lists

it('resolves list<array{…}> into an array of objects', function (): void {
    $schema = structuralSchema('list<array{id: int}>');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('object')
        ->and($schema['items']['properties']['id']['type'])->toBe('integer');
});

it('resolves the array{…}[] suffix form into an array of objects', function (): void {
    $schema = structuralSchema('array{id: int}[]');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('object')
        ->and($schema['items']['properties']['id']['type'])->toBe('integer');
});

it('resolves a scalar list (string[]) into an array of scalars', function (): void {
    $schema = structuralSchema('string[]');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string');
});

it('resolves array<int, T> into a list of T', function (): void {
    $schema = structuralSchema('array<int, string>');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string');
});

// endregion

// region Maps

it('resolves array<string, T> into an object with additionalProperties', function (): void {
    $schema = structuralSchema('array<string, int>');

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties']['type'])->toBe('integer');
});

it('resolves array<string, mixed> into a permissive map', function (): void {
    $schema = structuralSchema('array<string, mixed>');

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties'])->toBeTrue();
});

it('resolves Collection<string, T> into an object with additionalProperties', function (): void {
    $schema = structuralSchema('Illuminate\Support\Collection<string, int>');

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties']['type'])->toBe('integer');
});

it('resolves Collection<string, ClassName> into a map of $ref values', function (): void {
    $schema = structuralSchema('Illuminate\Support\Collection<string, \stdClass>');

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties'])->toBe(['$ref' => '#/components/schemas/stdClass']);
});

it('resolves a nested Collection<string, Collection<string, T>> map', function (): void {
    $schema = structuralSchema(
        'Illuminate\Support\Collection<string, Illuminate\Support\Collection<string, int>>',
    );

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties']['type'])->toBe('object')
        ->and($schema['additionalProperties']['additionalProperties']['type'])->toBe('integer');
});

it('resolves Collection<int, T> into a list of T', function (): void {
    $schema = structuralSchema('Illuminate\Support\Collection<int, \stdClass>');

    expect($schema['type'])->toBe('array')
        ->and($schema['items'])->toBe(['$ref' => '#/components/schemas/stdClass']);
});

it('treats the other recognised collection generics as array-like', function (): void {
    foreach (
        [
            'Illuminate\Database\Eloquent\Collection',
            'Illuminate\Support\LazyCollection',
            'Illuminate\Support\Enumerable',
        ] as $base
    ) {
        $schema = structuralSchema("{$base}<string, int>");

        expect($schema['type'])->toBe('object')
            ->and($schema['additionalProperties']['type'])->toBe('integer');
    }
});

// endregion

// region Leaf classes + degradation

it('resolves a bare scalar identifier', function (): void {
    expect(structuralSchema('string'))->toBe(['type' => 'string'])
        ->and(structuralSchema('bool'))->toBe(['type' => 'boolean']);
});

it('delegates a leaf class identifier to the leaf-class callback', function (): void {
    $schema = structuralSchema('array{user: \stdClass}');

    expect($schema['properties']['user'])->toBe(['$ref' => '#/components/schemas/stdClass']);
});

it('degrades to null for an unresolvable generic value class', function (): void {
    // A single unverifiable class name fails the symfony resolve; the boundary returns null and the
    // caller keeps its own fallback (pinned canonical, characterization class 5).
    expect(structuralSchema('list<NotARealClassXyz>'))->toBe(['__null__' => true]);
});

it('degrades to null for an unrecognised (non-array-like) generic', function (): void {
    // SplObjectStorage is not a recognised collection class, so it is not array-like.
    $schema = structuralSchema('SplObjectStorage<\stdClass, int>');

    expect($schema['type'])->toBe('string')
        ->and($schema['description'])->toContain('Unmapped');
});

// endregion

// region Enum parity (characterization class 1)

it('promotes a backed enum leaf to a $ref component with case descriptions', function (): void {
    $registry = new ComponentSchemaRegistry();
    $engine = new JsonSchemaFromType(new NullLogger(), $registry);

    $type = new TypeNodeResolver()->toType(
        parsePhpDocType('\\' . DescribedStatus::class),
        new ReflectionClass(stdClass::class),
    );
    assert($type instanceof Type);

    // No leaf callback: the built-in enum-component path applies.
    $schema = $engine->fromType($type);

    expect($schema->ref)->toContain('DescribedStatus');

    $key = $registry->keyFor(DescribedStatus::class);
    $component = json_decode(json_encode($registry->schemaForKey((string) $key)), true);

    expect($component['type'])->toBe('string')
        ->and($component['enum'])->toBe(['draft', 'published'])
        ->and($component['description'])->toContain('The article is still being written.');
});

// endregion

// region Leaf-callback precedence (amendment 5)

it('lets a non-null leaf callback win over the built-in enum path', function (): void {
    $engine = new JsonSchemaFromType(new NullLogger(), new ComponentSchemaRegistry());

    $type = new TypeNodeResolver()->toType(
        parsePhpDocType('\\' . DescribedStatus::class),
        new ReflectionClass(stdClass::class),
    );
    assert($type instanceof Type);

    $schema = $engine->fromType(
        $type,
        static fn(string $fqcn): OA\Schema => new OA\Schema(['ref' => "#/custom/{$fqcn}"]),
    );

    expect($schema->ref)->toBe('#/custom/' . DescribedStatus::class);
});

it('falls back to the built-in path when the leaf callback returns null', function (): void {
    $registry = new ComponentSchemaRegistry();
    $engine = new JsonSchemaFromType(new NullLogger(), $registry);

    $type = new TypeNodeResolver()->toType(
        parsePhpDocType('\\' . DescribedStatus::class),
        new ReflectionClass(stdClass::class),
    );
    assert($type instanceof Type);

    // A callback that declines (returns null) must not suppress the enum component.
    $schema = $engine->fromType($type, static fn(string $fqcn): ?OA\Schema => null);

    expect($schema->ref)->toContain('DescribedStatus');
});

// endregion
