<?php

declare(strict_types=1);

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\InlineValidationFixtureController;

uses()->group('openapi');

it('returns the first N top-level statements of a method body', function (): void {
    $scanner = new MethodBodyScanner();
    $method = new ReflectionMethod(InlineValidationFixtureController::class, 'lateValidate');

    $statements = $scanner->firstStatements($method, 10);

    expect($statements)->toHaveCount(10)
        ->and($statements[0])->toBeInstanceOf(Expression::class);
});

it('returns all statements when the body is shorter than the limit', function (): void {
    $scanner = new MethodBodyScanner();
    $method = new ReflectionMethod(InlineValidationFixtureController::class, 'store');

    $statements = $scanner->firstStatements($method, 10);

    expect($statements)->toHaveCount(2)
        ->and($statements)->each->toBeInstanceOf(Stmt::class);
});

it('returns an empty list for methods without a source file', function (): void {
    $scanner = new MethodBodyScanner();
    $method = new ReflectionMethod(DateTimeImmutable::class, 'format');

    expect($scanner->firstStatements($method, 10))->toBe([]);
});

it('parses each file once and shares the AST across calls', function (): void {
    $scanner = new MethodBodyScanner();
    $method = new ReflectionMethod(InlineValidationFixtureController::class, 'store');

    $first = $scanner->firstStatements($method, 10);
    $second = $scanner->firstStatements($method, 10);

    // Same node instances prove the cached AST was reused rather than re-parsed.
    expect($second[0])->toBe($first[0]);
});

it('reads a trailing line comment after a node', function (): void {
    $scanner = new MethodBodyScanner();
    $method = new ReflectionMethod(InlineValidationFixtureController::class, 'store');
    $file = $method->getFileName();

    $statements = $scanner->firstStatements($method, 1);

    // No trailing comment after the whole statement (it ends on its own line).
    expect($scanner->trailingCommentAfter((string) $file, $statements[0]))->toBeNull();
});
