<?php

declare(strict_types=1);

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use Radiergummi\OpenApi\Support\MethodBody\SchemaDefinitionFromLiteral;

uses()->group('openapi');

// region Helpers

function parseArrayLiteral(string $code): Array_
{
    $statements = new ParserFactory()->createForNewestSupportedVersion()->parse("<?php {$code};");
    $statement = $statements[0] ?? null;

    assert($statement instanceof Expression);
    assert($statement->expr instanceof Array_);

    return $statement->expr;
}

// endregion

// region type: array always carries items (#265)

it('emits items for an empty array literal node', function (): void {
    expect(SchemaDefinitionFromLiteral::fromValue(parseArrayLiteral('[]')))
        ->toBe(['type' => 'array', 'items' => []]);
});

it('emits items for an empty evaluated array', function (): void {
    expect(SchemaDefinitionFromLiteral::fromLiteralValue([]))
        ->toBe(['type' => 'array', 'items' => []]);
});

it('emits items for a heterogeneous evaluated list', function (): void {
    expect(SchemaDefinitionFromLiteral::fromLiteralValue([1, 'two']))
        ->toBe(['type' => 'array', 'items' => []]);
});

it('emits items for a list whose first element is unreadable', function (): void {
    // Two non-literal elements collapse to empty (`[]`) definitions; the list type is known
    // (array) but the item schema is not.
    expect(SchemaDefinitionFromLiteral::fromArrayNode(parseArrayLiteral('[random_int(0, 1), random_int(2, 3)]')))
        ->toBe(['type' => 'array', 'items' => []]);
});

it('still constrains items when every element agrees on a type', function (): void {
    expect(SchemaDefinitionFromLiteral::fromLiteralValue([1, 2, 3]))
        ->toBe(['type' => 'array', 'items' => ['type' => 'integer']]);
});

it('leaves items unconstrained for a list of objects with differing property shapes', function (): void {
    // Both elements are type: object, so a type-only comparison would impose the first element's
    // shape; the differing key sets must degrade to unconstrained items instead.
    expect(SchemaDefinitionFromLiteral::fromLiteralValue([
        ['status' => 'active', 'expires_at' => '2025-01-01'],
        ['status' => 'inactive'],
    ]))->toBe(['type' => 'array', 'items' => []]);
});

it('constrains items for a list of objects that share the same property shape', function (): void {
    expect(SchemaDefinitionFromLiteral::fromLiteralValue([
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ]))->toBe([
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
        ],
    ]);
});

// endregion
