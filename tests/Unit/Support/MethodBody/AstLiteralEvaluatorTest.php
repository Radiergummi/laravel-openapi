<?php

declare(strict_types=1);

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
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

/**
 * Evaluates the last statement of a snippet after a NameResolver pass — mirroring how the
 * MethodBodyScanner hands expressions to the evaluator, so `use` imports and aliases resolve.
 */
function evaluateResolvedLiteral(string $code): mixed
{
    $statements = new ParserFactory()->createForNewestSupportedVersion()->parse("<?php {$code}");

    assert($statements !== null);

    $resolved = new NodeTraverser(new NameResolver())->traverse($statements);
    $statement = end($resolved);

    assert($statement instanceof Expression);

    return AstLiteralEvaluator::evaluate($statement->expr);
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

// region Class constants on loadable classes

it('resolves a class constant on a fully-qualified loadable class', function (): void {
    expect(evaluateLiteral('\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN'))->toBe(403);
});

it('resolves a class constant through an aliased import, as the NameResolver pass leaves it', function (): void {
    $code = <<<'PHP'
        use Symfony\Component\HttpFoundation\Response as HttpResponse;

        HttpResponse::HTTP_NOT_FOUND;
        PHP;

    expect(evaluateResolvedLiteral($code))->toBe(404);
});

it('resolves a string class constant', function (): void {
    expect(evaluateLiteral('\Radiergummi\OpenApi\Tests\Fixtures\LiteralConstantsFixture::MESSAGE'))
        ->toBe('Cannot update a user prospect.');
});

it('resolves an array class constant whose values are all literals', function (): void {
    expect(evaluateLiteral('\Radiergummi\OpenApi\Tests\Fixtures\LiteralConstantsFixture::NESTED_ARRAY'))
        ->toBe(['a' => 1, 'b' => ['c' => true, 'd' => null]]);
});

it('resolves a constant on an interface', function (): void {
    expect(evaluateLiteral('\DateTimeInterface::ATOM'))->toBe('Y-m-d\TH:i:sP');
});

it('rejects class-constant fetches that do not resolve to a compile-time literal', function (string $code): void {
    evaluateLiteral($code);
})->throws(NonLiteralValueException::class)->with([
    'non-existent class' => ['\Vendor\Does\Not\Exist::SOMETHING'],
    'undefined constant on a real class' => ['\Symfony\Component\HttpFoundation\Response::HTTP_NO_SUCH_STATUS'],
    'enum case (an object, not a literal)' => ['\Radiergummi\OpenApi\Tests\Fixtures\StatusFixtureEnum::Draft'],
    'array constant containing an enum case' => ['\Radiergummi\OpenApi\Tests\Fixtures\LiteralConstantsFixture::CONTAINS_ENUM_CASE'],
    'unresolved self reference' => ['self::SOMETHING'],
    'dynamic class expression' => ['$class::HTTP_FORBIDDEN'],
    'dynamic constant name' => ['\Symfony\Component\HttpFoundation\Response::{$name}'],
]);

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
    'constant on an unloadable class' => ['Status::Draft'],
    'spread element' => ['["a", ...$more]'],
    'by-ref element' => ['[&$entry]'],
    'negated string' => ['-"nope"'],
    'non-scalar array key' => ['[true => "x"]'],
]);

// endregion

// region self:: / static:: resolution

class SelfConstantHost
{
    public const string FIELD = 'title';

    public const array SHAPE = ['a', 'b'];
}

it('resolves self:: against the supplied enclosing class', function (): void {
    expect(AstLiteralEvaluator::evaluate(parseExpression('self::FIELD'), SelfConstantHost::class))
        ->toBe('title');
});

it('resolves static:: against the supplied enclosing class', function (): void {
    expect(AstLiteralEvaluator::evaluate(parseExpression('static::FIELD'), SelfConstantHost::class))
        ->toBe('title');
});

it('resolves self:: keys nested inside an array literal', function (): void {
    expect(AstLiteralEvaluator::evaluate(parseExpression('[self::FIELD => 1]'), SelfConstantHost::class))
        ->toBe(['title' => 1]);
});

it('resolves self::class to the enclosing class name', function (): void {
    expect(AstLiteralEvaluator::evaluate(parseExpression('self::class'), SelfConstantHost::class))
        ->toBe(SelfConstantHost::class);
});

it('still throws on self:: when no enclosing class is supplied', function (): void {
    expect(static fn(): mixed => evaluateLiteral('self::FIELD'))
        ->toThrow(NonLiteralValueException::class);
});

// endregion
