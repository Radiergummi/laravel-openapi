<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\OperationTagMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new OperationTagMissing();

    expect($rule->id())->toBe('operation.tag-missing')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when an operation has no tags', function (): void {
    $rule = new OperationTagMissing();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/users', tags: []);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.tag-missing')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('/users');
});

it('emits no findings when operation has tags', function (): void {
    $rule = new OperationTagMissing();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/users', tags: ['Users']);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits findings only for untagged operations in a mixed set', function (): void {
    $rule = new OperationTagMissing();
    $context = OperationNodeFactory::emptyContext();

    $opWithTags = OperationNodeFactory::makeOperation(pathUri: '/users', tags: ['Users']);
    $opWithoutTags = OperationNodeFactory::makeOperation(pathUri: '/posts', tags: []);

    $findings = [
        ...iterator_to_array($rule->checkOperation($opWithTags, $context)),
        ...iterator_to_array($rule->checkOperation($opWithoutTags, $context)),
    ];

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('/posts');
});
