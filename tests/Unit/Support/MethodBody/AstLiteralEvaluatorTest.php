<?php

declare(strict_types=1);

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;

uses()->group('openapi');

// region Helpers

function parseExpression(string $code): Expr
{
    $statements = new ParserFactory()->createForNewestSupportedVersion()->parse("<?php {$code};");
    $statement = $statements[0] ?? null;

    assert($statement instanceof Expression);

    return $statement->expr;
}

function evaluateLiteral(string $code): mixed
{
    return AstLiteralEvaluator::evaluate(parseExpression($code));
}

// endregion

// region Literals

it('evaluates scalar literals', function (string $code, mixed $expected): void {
    expect(evaluateLiteral($code))->toBe($expected);
})->with([
    'string' => ['"hello"', 'hello'],
    'integer' => ['42', 42],
    'float' => ['3.14', 3.14],
    'true' => ['true', true],
    'false' => ['false', false],
    'null' => ['null', null],
    'negative integer' => ['-7', -7],
    'negative float' => ['-0.5', -0.5],
]);

it('evaluates nested array literals with mixed keys', function (): void {
    expect(evaluateLiteral('["a" => 1, "b" => ["c" => [true, null]], 5, 9 => "x"]'))->toBe([
        'a' => 1,
        'b' => ['c' => [true, null]],
        0 => 5,
        9 => 'x',
    ]);
});

it('resolves ::class constants to the class-name string', function (): void {
    expect(evaluateLiteral('Some\Domain\Payload::class'))->toBe('Some\Domain\Payload');
});

// endregion

// region Rejection paths

it('rejects non-literal expressions', function (string $code): void {
    evaluateLiteral($code);
})->throws(NonLiteralValueException::class)->with([
    'variable' => ['$rules'],
    'function call' => ['buildRules()'],
    'method call' => ['$this->rules()'],
    'static call' => ['Rule::in(["a"])'],
    'object instantiation' => ['new Enum(Status::class)'],
    'string concatenation' => ['"max:" . $limit'],
    'named constant' => ['SOME_CONSTANT'],
    'non-class class constant' => ['Status::Draft'],
    'spread element' => ['["a", ...$more]'],
    'by-ref element' => ['[&$entry]'],
    'negated string' => ['-"nope"'],
    'non-scalar array key' => ['[true => "x"]'],
]);

// endregion
