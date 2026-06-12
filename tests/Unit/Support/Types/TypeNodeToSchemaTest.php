<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Types;

use OpenApi\Annotations as OA;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Radiergummi\OpenApi\Support\Types\TypeNodeToSchema;
use ReflectionClass;
use stdClass;

uses()->group('openapi');

function parseType(string $expression): TypeNode
{
    $config = new ParserConfig([]);
    $lexer = new Lexer($config);
    $typeParser = new TypeParser($config, new ConstExprParser($config));

    return $typeParser->parse(new TokenIterator($lexer->tokenize($expression)));
}

/**
 * Resolves a PHPDoc type expression to a schema. The class-leaf strategy maps any resolved FQCN
 * to a sentinel `$ref` so leaf delegation is observable in assertions.
 */
function resolveType(string $expression): ?OA\Schema
{
    return new TypeNodeToSchema()->resolve(
        parseType($expression),
        new ReflectionClass(stdClass::class),
        static fn(string $fqcn): OA\Schema => new OA\Schema(['ref' => "#/components/schemas/{$fqcn}"]),
    );
}

/**
 * The JSON-Schema array view of a resolved type. Asserts the type resolved (non-null) so callers
 * can index the result directly.
 *
 * @return array<string, mixed>
 */
function resolveTypeToArray(string $expression): array
{
    $schema = resolveType($expression);
    assert($schema instanceof OA\Schema);

    return json_decode(json_encode($schema), associative: true);
}

it('resolves an array shape into an object schema with required properties', function (): void {
    $schema = resolveTypeToArray('array{foo: string, bar: int}');

    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['foo']['type'])->toBe('string')
        ->and($schema['properties']['bar']['type'])->toBe('integer')
        ->and($schema['required'])->toBe(['foo', 'bar']);
});

it('omits optional keys from required', function (): void {
    $schema = resolveTypeToArray('array{name?: string, id: int}');

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['name', 'id'])
        ->and($schema['required'])->toBe(['id']);
});

it('emits no required key when every shape item is optional', function (): void {
    $schema = resolveTypeToArray('array{name?: string}');

    expect($schema['type'])->toBe('object')
        ->and($schema)->not->toHaveKey('required');
});

it('resolves a nested array shape into a nested object', function (): void {
    $schema = resolveTypeToArray('array{data: array{id: int}}');

    expect($schema['properties']['data']['type'])->toBe('object')
        ->and($schema['properties']['data']['properties']['id']['type'])->toBe('integer')
        ->and($schema['properties']['data']['required'])->toBe(['id']);
});

it('resolves list<array{…}> into an array of objects', function (): void {
    $schema = resolveTypeToArray('list<array{id: int}>');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('object')
        ->and($schema['items']['properties']['id']['type'])->toBe('integer');
});

it('resolves the array{…}[] suffix form into an array of objects', function (): void {
    $schema = resolveTypeToArray('array{id: int}[]');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('object')
        ->and($schema['items']['properties']['id']['type'])->toBe('integer');
});

it('resolves a scalar list (string[]) into an array of scalars', function (): void {
    $schema = resolveTypeToArray('string[]');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string');
});

it('resolves array<string, T> into an object with additionalProperties', function (): void {
    $schema = resolveTypeToArray('array<string, int>');

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties']['type'])->toBe('integer');
});

it('resolves array<int, T> into a list of T', function (): void {
    $schema = resolveTypeToArray('array<int, string>');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string');
});

it('resolves a bare scalar identifier', function (): void {
    expect(resolveTypeToArray('string'))->toBe(['type' => 'string'])
        ->and(resolveTypeToArray('bool'))->toBe(['type' => 'boolean']);
});

it('delegates a leaf class identifier to the class-schema strategy', function (): void {
    $schema = resolveTypeToArray('array{user: \stdClass}');

    expect($schema['properties']['user'])->toBe(['$ref' => '#/components/schemas/stdClass']);
});

it('quotes and integers become property names', function (): void {
    $schema = resolveTypeToArray("array{'quoted-key': string, 0: int}");

    expect($schema['properties'])->toHaveKeys(['quoted-key', '0'])
        ->and($schema['properties']['quoted-key']['type'])->toBe('string')
        ->and($schema['properties']['0']['type'])->toBe('integer');
});

it('keeps an unreadable shape value as a present-but-untyped property', function (): void {
    $schema = resolveTypeToArray('array{known: int, mystery: NotARealClassXyz}');

    expect($schema['properties'])->toHaveKeys(['known', 'mystery'])
        ->and($schema['properties']['mystery'])->toBe([])
        ->and($schema['required'])->toBe(['known', 'mystery']);
});

it('always emits items for a list, even when the element is unreadable', function (): void {
    $schema = resolveTypeToArray('list<NotARealClassXyz>');

    expect($schema['type'])->toBe('array')
        ->and($schema)->toHaveKey('items');
});

it('returns null for a non-array generic so the caller keeps its fallback', function (): void {
    expect(resolveType('Illuminate\Support\Collection<int, \stdClass>'))->toBeNull();
});

it('returns null for a bare array identifier with no shape', function (): void {
    expect(resolveType('array'))->toBeNull();
});

it('returns null for a multi-class nullable union (not a simple nullable)', function (): void {
    expect(resolveType('\stdClass|\Exception|null'))->toBeNull();
});

it('returns null for a node kind it does not model (intersection)', function (): void {
    expect(resolveType('\stdClass&\Countable'))->toBeNull();
});

it('uses a class-constant shape key verbatim as the property name', function (): void {
    $schema = resolveTypeToArray('array{self::FOO: int}');

    expect($schema['properties'])->toHaveKey('self::FOO')
        ->and($schema['properties']['self::FOO']['type'])->toBe('integer');
});

it('wraps a nullable array shape with the OAS 3.1 null idiom', function (): void {
    $schema = new TypeNodeToSchema()->resolve(
        parseType('?array{id: int}'),
        new ReflectionClass(stdClass::class),
        static fn(string $fqcn): OA\Schema => new OA\Schema([]),
    );

    expect($schema->oneOf)->toHaveCount(2)
        ->and($schema->oneOf[1]->type)->toBe('null');
});
