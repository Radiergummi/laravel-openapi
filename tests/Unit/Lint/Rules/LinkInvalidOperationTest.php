<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\LinkInvalidOperation;
use Radiergummi\OpenApi\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeLinkInvalidOperationNode(?string $operationId): LinkNode
{
    $link = OperationNodeFactory::makeLink(operationId: $operationId);
    OperationNodeFactory::makeOperation(
        method: 'POST',
        responses: [OperationNodeFactory::makeResponse(statusCode: 201, description: null, links: [$link])],
    );

    return $link;
}

it('reports its id and level', function (): void {
    $rule = new LinkInvalidOperation();

    expect($rule->id())->toBe('link.invalid-operation')->and($rule->level())->toBe(1);
});

it('emits no finding when all Link operationIds resolve', function (): void {
    $link = makeLinkInvalidOperationNode(operationId: 'foo.show');
    $context = OperationNodeFactory::emptyContext(
        operationsByOperationId: ['foo.show' => OperationNodeFactory::makeOperation(operationId: 'foo.show')],
    );

    $findings = iterator_to_array(new LinkInvalidOperation()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a Link operationId is unknown', function (): void {
    $link = makeLinkInvalidOperationNode(operationId: 'missing.endpoint');
    $context = OperationNodeFactory::emptyContext(
        operationsByOperationId: ['foo.show' => OperationNodeFactory::makeOperation(operationId: 'foo.show')],
    );

    $findings = iterator_to_array(new LinkInvalidOperation()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('link.invalid-operation')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('missing.endpoint');
});

it('emits no finding when link has no operationId', function (): void {
    $link = makeLinkInvalidOperationNode(operationId: null);

    $findings = iterator_to_array(
        new LinkInvalidOperation()->checkLink($link, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
