<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\LinkNeitherOperationIdNorRef;
use Radiergummi\OpenApi\Core\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeLinkNeitherFieldNode(?string $operationId, ?string $operationRef): LinkNode
{
    $link = OperationNodeFactory::makeLink(
        operationId: $operationId,
        operationRef: $operationRef,
    );
    OperationNodeFactory::makeOperation(
        method: 'POST',
        responses: [OperationNodeFactory::makeResponse(statusCode: 201, description: null, links: [$link])],
    );

    return $link;
}

it('reports its id and level', function (): void {
    $rule = new LinkNeitherOperationIdNorRef();

    expect($rule->id())->toBe('link.neither-operation-id-nor-ref')->and($rule->level())->toBe(0);
});

it(
    'emits no finding when a link has at least one of operationId / operationRef',
    function (?string $operationId, ?string $operationRef): void {
        $link = makeLinkNeitherFieldNode($operationId, $operationRef);

        $findings = iterator_to_array(
            new LinkNeitherOperationIdNorRef()->checkLink($link, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    'only operationId'  => ['foo.show', null],
    'only operationRef' => [null, '#/paths/~1foo/get'],
]);

it('emits a finding when a link has neither operationId nor operationRef', function (): void {
    $link = makeLinkNeitherFieldNode(null, null);

    $findings = iterator_to_array(
        new LinkNeitherOperationIdNorRef()->checkLink($link, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('link.neither-operation-id-nor-ref')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('neither');
});
