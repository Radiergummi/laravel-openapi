<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ActionMethodMissing;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionMethodController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\InvokableActionController;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * Builds a descriptor mirroring what RouteIntrospector produces for a controller action: the raw
 * Route carries the action string, while controller/method reflectors are null whenever the method
 * does not exist (the introspector degrades rather than throwing).
 */
function actionMethodDescriptor(string $action, ?ReflectionClass $controller = null, ?ReflectionMethod $method = null): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/__test__', ['uses' => $action, 'controller' => $action]),
        controller: $controller,
        method: $method,
        summary: null,
        description: null,
    );
}

function actionMethodFindings(ActionDescriptor $descriptor): array
{
    return iterator_to_array(
        new ActionMethodMissing()->checkRoute($descriptor, OperationNodeFactory::emptyContext()),
    );
}

it('has the correct rule id and severity', function (): void {
    $rule = new ActionMethodMissing();

    expect($rule->id)
        ->toBe('operation.action-method-missing')
        ->and($rule->severity)->toBe(Severity::Degraded);
});

it('flags a route whose controller method does not exist', function (): void {
    $descriptor = actionMethodDescriptor(ActionMethodController::class . '@destroy');

    expect(actionMethodFindings($descriptor))->toEmitFinding(
        ruleId: 'operation.action-method-missing',
        messageContains: 'ActionMethodController::destroy()',
    );
});

it('does not flag a route whose controller method exists', function (): void {
    $descriptor = actionMethodDescriptor(
        ActionMethodController::class . '@index',
        new ReflectionClass(ActionMethodController::class),
        new ReflectionMethod(ActionMethodController::class, 'index'),
    );

    expect(actionMethodFindings($descriptor))->toBe([]);
});

it('does not flag an invokable controller that implements __invoke', function (): void {
    $descriptor = actionMethodDescriptor(
        InvokableActionController::class,
        new ReflectionClass(InvokableActionController::class),
        new ReflectionMethod(InvokableActionController::class, '__invoke'),
    );

    expect(actionMethodFindings($descriptor))->toBe([]);
});

it('emits exactly one finding for a missing method', function (): void {
    $descriptor = actionMethodDescriptor(ActionMethodController::class . '@store');

    expect(actionMethodFindings($descriptor))->toHaveCount(1);
});

it('does not flag a closure route', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/__test__', ['uses' => static fn(): array => []]),
        controller: null,
        method: null,
        summary: null,
        description: null,
        closure: new ReflectionFunction(static fn(): array => []),
    );

    expect(actionMethodFindings($descriptor))->toBe([]);
});

it('does not flag a route whose controller class does not exist', function (): void {
    $action = 'App\\Http\\Controllers\\NonexistentController@index';

    $descriptor = actionMethodDescriptor($action);

    expect(actionMethodFindings($descriptor))->toBe([]);
});
