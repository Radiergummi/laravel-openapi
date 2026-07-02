<?php

declare(strict_types=1);

use PhpParser\Node\Expr\New_ as NewExpression;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Return_;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\ReturnExpressionResolver;
use Radiergummi\OpenApi\Support\MethodBody\ReturnVariableRefusal;
use Radiergummi\OpenApi\Support\MethodBody\ReturnVariableResolution;
use Radiergummi\OpenApi\Tests\Fixtures\ReturnExpressionResolverFixture;

uses()->group('openapi');

/**
 * Scans the fixture method and resolves its final top-level `return $variable;`.
 */
function resolveReturnVariable(string $method): ReturnVariableResolution
{
    $resolver = new ReturnExpressionResolver();
    $statements = new MethodBodyScanner()->firstStatements(
        new ReflectionMethod(ReturnExpressionResolverFixture::class, $method),
        limit: 10,
    );

    $variable = null;

    foreach ($statements as $statement) {
        if ($statement instanceof Return_ && $statement->expr instanceof Variable) {
            $variable = $statement->expr;
        }
    }

    if (!$variable instanceof Variable) {
        throw new RuntimeException("Fixture method {$method} has no top-level return of a variable.");
    }

    return $resolver->resolveVariable($variable, $statements);
}

it('resolves a single unconditional assignment to its expression', function (): void {
    $resolution = resolveReturnVariable('singleAssignment');

    expect($resolution->expression)->toBeInstanceOf(NewExpression::class)
        ->and($resolution->refusal)->toBeNull();
});

it('refuses a conditional assignment as not-assigned-once', function (): void {
    $resolution = resolveReturnVariable('conditionalAssignment');

    expect($resolution->expression)->toBeNull()
        ->and($resolution->refusal)->toBe(ReturnVariableRefusal::NotAssignedOnce);
});

it('refuses multiple unconditional assignments as not-assigned-once', function (): void {
    $resolution = resolveReturnVariable('multipleAssignments');

    expect($resolution->expression)->toBeNull()
        ->and($resolution->refusal)->toBe(ReturnVariableRefusal::NotAssignedOnce);
});

it('refuses an element write after the assignment as mutation', function (): void {
    $resolution = resolveReturnVariable('elementWriteAfterAssignment');

    expect($resolution->expression)->toBeNull()
        ->and($resolution->refusal)->toBe(ReturnVariableRefusal::MutatedAfterAssignment);
});

it('refuses a compound assignment after the assignment as mutation', function (): void {
    $resolution = resolveReturnVariable('compoundAssignAfterAssignment');

    expect($resolution->expression)->toBeNull()
        ->and($resolution->refusal)->toBe(ReturnVariableRefusal::MutatedAfterAssignment);
});

it('refuses a variable with no matching assignment as not-assigned-once', function (): void {
    $resolution = resolveReturnVariable('noMatchingAssignment');

    expect($resolution->expression)->toBeNull()
        ->and($resolution->refusal)->toBe(ReturnVariableRefusal::NotAssignedOnce);
});

it('refuses a dynamically-named returned variable', function (): void {
    $resolution = resolveReturnVariable('dynamicallyNamedVariable');

    expect($resolution->expression)->toBeNull()
        ->and($resolution->refusal)->toBe(ReturnVariableRefusal::DynamicallyNamedVariable);
});

it('does not descend into a closure body when collecting method-level returns', function (): void {
    $resolver = new ReturnExpressionResolver();
    $statements = new MethodBodyScanner()->firstStatements(
        new ReflectionMethod(ReturnExpressionResolverFixture::class, 'returnsClosureNotThisMethod'),
        limit: 10,
    );

    expect($resolver->methodLevelReturns($statements))->toHaveCount(1);
});
