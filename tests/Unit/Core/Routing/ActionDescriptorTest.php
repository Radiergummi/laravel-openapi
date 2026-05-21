<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Unit\Core\Routing\Fixtures\Alpha;
use Radiergummi\OpenApi\Tests\Unit\Core\Routing\Fixtures\AttributedController;
use Radiergummi\OpenApi\Tests\Unit\Core\Routing\Fixtures\Beta;

uses()->group('routing', 'openapi');

function makeAttributedDescriptor(string $methodName = 'action'): ActionDescriptor
{
    $controller = new ReflectionClass(AttributedController::class);
    $method = new ReflectionMethod(AttributedController::class, $methodName);

    return new ActionDescriptor(
        route: new Route(['GET'], '/things', [AttributedController::class, $methodName]),
        controller: $controller,
        method: $method,
        summary: null,
        description: null,
    );
}

it('buckets controller attributes by FQCN and ignores unrelated ones', function (): void {
    $descriptor = makeAttributedDescriptor();

    $alphas = $descriptor->controllerAttributes(Alpha::class);
    $betas = $descriptor->controllerAttributes(Beta::class);

    expect($alphas)->toHaveCount(2)
        ->and($alphas[0]->getName())->toBe(Alpha::class)
        ->and($alphas[0]->newInstance()->label)->toBe('class-1')
        ->and($alphas[1]->newInstance()->label)->toBe('class-2')
        ->and($betas)->toHaveCount(1)
        ->and($betas[0]->getName())->toBe(Beta::class);
});

it('returns the action reflectors attributes for actionAttributes()', function (): void {
    $descriptor = makeAttributedDescriptor();

    $alphas = $descriptor->actionAttributes(Alpha::class);

    expect($alphas)->toHaveCount(1)
        ->and($alphas[0]->newInstance()->label)->toBe('action-1');
});

it('returns identical ReflectionAttribute instances on repeated calls (cached buckets)', function (): void {
    $descriptor = makeAttributedDescriptor();

    $first = $descriptor->controllerAttributes(Alpha::class);
    $second = $descriptor->controllerAttributes(Alpha::class);

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2)
        ->and($second[0])->toBe($first[0])
        ->and($second[1])->toBe($first[1]);
});

it('returns an empty list when the attribute class is not declared', function (): void {
    $descriptor = makeAttributedDescriptor('bareAction');

    expect($descriptor->actionAttributes(Alpha::class))->toBe([])
        ->and($descriptor->actionAttributes(Beta::class))->toBe([]);
});

it('returns empty lists when no controller or action reflector is present', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/orphan', static fn() => null),
        controller: null,
        method: null,
        summary: null,
        description: null,
        closure: null,
    );

    expect($descriptor->controllerAttributes(Alpha::class))->toBe([])
        ->and($descriptor->actionAttributes(Alpha::class))->toBe([]);
});
