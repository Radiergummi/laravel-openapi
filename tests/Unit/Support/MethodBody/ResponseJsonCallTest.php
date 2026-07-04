<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\MethodBody;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use Radiergummi\OpenApi\Support\MethodBody\ResponseJsonCall;

uses()->group('openapi');

/**
 * @param list<Arg> $arguments
 */
function responseJsonMethodCall(array $arguments, string $method = 'json', ?Expr $receiver = null): MethodCall
{
    $receiver ??= new FuncCall(new Name('response'));

    return new MethodCall($receiver, new Identifier($method), $arguments);
}

it('matches a response()->json(<data>, <status>) call and exposes the data argument', function (): void {
    $data = new Variable('resource');
    $call = responseJsonMethodCall([new Arg($data), new Arg(new Int_(201))]);

    expect(ResponseJsonCall::matches($call))->toBeTrue()
        ->and(ResponseJsonCall::dataArgument($call)?->value)->toBe($data);
});

it('finds a named data argument regardless of position', function (): void {
    $data = new Variable('resource');
    $call = responseJsonMethodCall([
        new Arg(new Int_(201), name: new Identifier('status')),
        new Arg($data, name: new Identifier('data')),
    ]);

    expect(ResponseJsonCall::dataArgument($call)?->value)->toBe($data);
});

it('matches a fully-qualified \response()->json() receiver', function (): void {
    $call = responseJsonMethodCall([new Arg(new Variable('resource'))], receiver: new FuncCall(new FullyQualified('response')));

    expect(ResponseJsonCall::matches($call))->toBeTrue();
});

it('returns null for a json() call that carries no data argument', function (): void {
    $call = responseJsonMethodCall([]);

    expect(ResponseJsonCall::matches($call))->toBeTrue()
        ->and(ResponseJsonCall::dataArgument($call))->toBeNull();
});

it('refuses a non-json method on the response() helper', function (): void {
    $call = responseJsonMethodCall([new Arg(new String_('No Content'))], method: 'noContent');

    expect(ResponseJsonCall::matches($call))->toBeFalse()
        ->and(ResponseJsonCall::dataArgument($call))->toBeNull();
});

it('refuses ->json() on a receiver that is not the response() helper', function (): void {
    $call = responseJsonMethodCall([new Arg(new Variable('resource'))], receiver: new Variable('service'));

    expect(ResponseJsonCall::matches($call))->toBeFalse();
});

it('refuses when response() is called with arguments', function (): void {
    $call = responseJsonMethodCall(
        [new Arg(new Variable('resource'))],
        receiver: new FuncCall(new Name('response'), [new Arg(new String_('body'))]),
    );

    expect(ResponseJsonCall::matches($call))->toBeFalse();
});

it('refuses a first-class callable ->json(...)', function (): void {
    $callable = new MethodCall(new FuncCall(new Name('response')), new Identifier('json'), [new VariadicPlaceholder()]);

    expect(ResponseJsonCall::matches($callable))->toBeFalse();
});

it('refuses a non-method-call node', function (): void {
    expect(ResponseJsonCall::matches(new Variable('x')))->toBeFalse()
        ->and(ResponseJsonCall::dataArgument(new Variable('x')))->toBeNull();
});
