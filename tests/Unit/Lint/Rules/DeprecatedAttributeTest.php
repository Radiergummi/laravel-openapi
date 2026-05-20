<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\DeprecatedAttribute;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DeprecatedAttrClassController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DeprecatedAttrController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');

    expect($rule->id())->toBe('deprecated.attribute')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when a method uses a deprecated OpenAPI attribute', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');
    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrController::class, 'withDeprecatedAttribute', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('deprecated.attribute')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('deprecated');
});

it('emits no findings when a method uses a non-deprecated OpenAPI attribute', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');
    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrController::class, 'withNonDeprecatedAttribute', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when a method has no attributes', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');
    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrController::class, 'withoutAttributes', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('uses class-level message wording when the deprecated attribute is on the controller class', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');

    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrClassController::class, 'index', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('class DeprecatedAttrClassController')
        ->and($findings[0]->message)->not->toContain('::index()');
});

it('uses method-level message wording when the deprecated attribute is on the method', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');
    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrController::class, 'withDeprecatedAttribute', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('DeprecatedAttrController::withDeprecatedAttribute()');
});
