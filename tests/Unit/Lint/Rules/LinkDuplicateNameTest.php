<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\LinkDuplicateName;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DuplicateLinkNameController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeLinkDuplicateNameOperation(string $method): OperationNode
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(DuplicateLinkNameController::class, $method, '/fixture');

    return OperationNodeFactory::forDescriptor(
        $descriptor,
        pathUri: '/fixture',
        operationId: 'fixture.' . $method,
    );
}

it('reports its id and level', function (): void {
    $rule = new LinkDuplicateName();

    expect($rule->id())->toBe('link.duplicate-name')->and($rule->level())->toBe(0);
});

it('emits a finding when a method has duplicate link names', function (): void {
    $rule = new LinkDuplicateName();
    $operation = makeLinkDuplicateNameOperation('withDuplicateLinks');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('link.duplicate-name')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('"GetProject"')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits no findings when all link names are unique', function (): void {
    $rule = new LinkDuplicateName();
    $operation = makeLinkDuplicateNameOperation('withUniqueLinks');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('emits no findings when operation has no descriptor', function (): void {
    $rule = new LinkDuplicateName();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/no-descriptor',
        operationId: 'no.descriptor',
        responses: [],
    );

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});
