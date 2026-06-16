<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\TagDuplicate;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DuplicateTagController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeTagDuplicateOperation(string $method): OperationNode
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(DuplicateTagController::class, $method, '/fixture');

    return OperationNodeFactory::forDescriptor(
        $descriptor,
        pathUri: '/fixture',
        operationId: 'fixture.' . $method,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new TagDuplicate();

    expect($rule->id())->toBe('tag.duplicate')->and($rule->severity())->toBe(Severity::Inconsistent);
});

it('emits a finding when a method has duplicate tags', function (): void {
    $operation = makeTagDuplicateOperation('withDuplicateTags');

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('tag.duplicate')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)->toContain('"Search"')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits no findings when all tags are unique', function (): void {
    $operation = makeTagDuplicateOperation('withUniqueTags');

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when a method has no tags', function (): void {
    $operation = makeTagDuplicateOperation('withoutTags');

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('detects duplicate enum-backed tags by their value', function (): void {
    $operation = makeTagDuplicateOperation('withDuplicateEnumTags');

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"active"')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits no findings when the operation has no descriptor', function (): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/no-descriptor',
        operationId: 'no.descriptor',
        responses: [],
    );

    $findings = iterator_to_array(
        new TagDuplicate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
