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

// endregion
