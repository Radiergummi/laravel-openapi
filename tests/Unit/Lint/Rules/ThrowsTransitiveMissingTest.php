<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Lint\Rules\ThrowsTransitiveMissing;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\TransitiveThrowsController;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @param list<string> $throws
 */
function makeTransitiveThrowsDescriptor(string $method, array $throws = []): ActionDescriptor
{
    $reflection = new ReflectionMethod(TransitiveThrowsController::class, $method);
    $route = new Route(['GET'], '/fixture', [TransitiveThrowsController::class, $method]);

    return new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
        throws: $throws,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new ThrowsTransitiveMissing();

    expect($rule->id())->toBe('throws.transitive-missing')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when a controller method is missing a transitive @throws', function (): void {
    $rule = new ThrowsTransitiveMissing();
    $operation = OperationNodeFactory::forDescriptor(
        makeTransitiveThrowsDescriptor('missingThrows'),
    );
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('throws.transitive-missing')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('FakeAction')
        ->and($findings[0]->message)->toContain('RuntimeException')
        ->and($findings[0]->message)->toContain('missingThrows');
});

it('emits no findings', function (string $method, array $throws): void {
    $rule = new ThrowsTransitiveMissing();
    $operation = OperationNodeFactory::forDescriptor(
        makeTransitiveThrowsDescriptor($method, $throws),
    );
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
})->with([
    'controller method redeclares the throws' => ['withThrows', ['RuntimeException']],
    'method has no action parameters' => ['noAction', []],
]);
