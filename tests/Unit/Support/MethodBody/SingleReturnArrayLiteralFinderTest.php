<?php

declare(strict_types=1);

use PhpParser\Node\Expr\Array_;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use Radiergummi\OpenApi\Tests\Fixtures\ReturnLiteralFixture;

uses()->group('openapi');

function returnLiteralFinder(): SingleReturnArrayLiteralFinder
{
    return new SingleReturnArrayLiteralFinder(new MethodBodyScanner());
}

it('finds the array literal of a single top-level return', function (): void {
    $literal = returnLiteralFinder()->find(new ReflectionMethod(ReturnLiteralFixture::class, 'singleLiteral'));

    expect($literal)
        ->toBeInstanceOf(Array_::class)
        ->and($literal->items)->toHaveCount(2);
});

it('accepts straight-line statements before the literal return', function (): void {
    $literal = returnLiteralFinder()->find(new ReflectionMethod(ReturnLiteralFixture::class, 'precededByStatements'));

    expect($literal)->toBeInstanceOf(Array_::class);
});

it('ignores return statements inside closure values', function (): void {
    $literal = returnLiteralFinder()->find(
        new ReflectionMethod(ReturnLiteralFixture::class, 'closureValueWithInnerReturn'),
    );

    expect($literal)->toBeInstanceOf(Array_::class);
});

it('refuses a method with an early conditional return', function (): void {
    $literal = returnLiteralFinder()->find(new ReflectionMethod(ReturnLiteralFixture::class, 'earlyReturnGuard'));

    expect($literal)->toBeNull();
});

it('resolves a return of a variable assigned a single array literal', function (): void {
    $literal = returnLiteralFinder()->find(new ReflectionMethod(ReturnLiteralFixture::class, 'variableReturn'));

    expect($literal)
        ->toBeInstanceOf(Array_::class)
        ->and($literal->items)->toHaveCount(1);
});

it('refuses a variable assigned conditionally', function (): void {
    $literal = returnLiteralFinder()->find(
        new ReflectionMethod(ReturnLiteralFixture::class, 'conditionalVariableAssignment'),
    );

    expect($literal)->toBeNull();
});

it('refuses a variable assigned more than once', function (): void {
    $literal = returnLiteralFinder()->find(
        new ReflectionMethod(ReturnLiteralFixture::class, 'variableAssignedTwice'),
    );

    expect($literal)->toBeNull();
});

it('refuses a variable assigned a non-array expression', function (): void {
    $literal = returnLiteralFinder()->find(
        new ReflectionMethod(ReturnLiteralFixture::class, 'variableAssignedNonArray'),
    );

    expect($literal)->toBeNull();
});

it('refuses a returned variable that is an unassigned parameter', function (): void {
    $literal = returnLiteralFinder()->find(
        new ReflectionMethod(ReturnLiteralFixture::class, 'parameterReturn'),
    );

    expect($literal)->toBeNull();
});

it('refuses a computed return expression', function (): void {
    $literal = returnLiteralFinder()->find(new ReflectionMethod(ReturnLiteralFixture::class, 'mergedReturn'));

    expect($literal)->toBeNull();
});

it('refuses a return beyond the statement limit', function (): void {
    $literal = returnLiteralFinder()->find(new ReflectionMethod(ReturnLiteralFixture::class, 'beyondStatementLimit'));

    expect($literal)->toBeNull();
});

it('refuses a method without a return', function (): void {
    $literal = returnLiteralFinder()->find(new ReflectionMethod(ReturnLiteralFixture::class, 'noReturn'));

    expect($literal)->toBeNull();
});
