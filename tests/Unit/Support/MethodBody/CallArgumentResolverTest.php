<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\MethodBody;

use PhpParser\Node\Arg;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Radiergummi\OpenApi\Support\MethodBody\CallArgumentResolver;

uses()->group('openapi');

function positionalArgument(string $value): Arg
{
    return new Arg(new String_($value));
}

function namedArgument(string $name, string $value): Arg
{
    return new Arg(new String_($value), name: new Identifier($name));
}

it('resolves a named argument at any position', function (): void {
    $arguments = [positionalArgument('data'), namedArgument('status', 'named')];

    expect(CallArgumentResolver::argument($arguments, 'status', 1))
        ->toBe($arguments[1]);
});

it('resolves an unnamed argument by position', function (): void {
    $arguments = [positionalArgument('data'), positionalArgument('status')];

    expect(CallArgumentResolver::argument($arguments, 'status', 1))
        ->toBe($arguments[1]);
});

it('returns null when a differently named argument sits at the position', function (): void {
    $arguments = [positionalArgument('data'), namedArgument('headers', 'other')];

    expect(CallArgumentResolver::argument($arguments, 'status', 1))
        ->toBeNull();
});

it('returns null when no argument matches by name or position', function (): void {
    $arguments = [positionalArgument('data')];

    expect(CallArgumentResolver::argument($arguments, 'status', 1))
        ->toBeNull();
});

it('never matches a positional slot for a negative position', function (): void {
    $arguments = [positionalArgument('data'), positionalArgument('status')];

    expect(CallArgumentResolver::argument($arguments, 'status', -1))
        ->toBeNull();
});
