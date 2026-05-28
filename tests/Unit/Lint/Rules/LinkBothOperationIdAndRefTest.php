<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\LinkBothOperationIdAndRef;
use Radiergummi\OpenApi\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeLinkBothFieldsNode(?string $operationId, ?string $operationRef): LinkNode
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
    $rule = new LinkBothOperationIdAndRef();

    expect($rule->id())->toBe('link.both-operation-id-and-ref')->and($rule->level())->toBe(0);
});

it(
    'emits no finding when a link has only one of operationId / operationRef (or neither)',
    function (?string $operationId, ?string $operationRef): void {
        $link = makeLinkBothFieldsNode($operationId, $operationRef);

        $findings = iterator_to_array(
            new LinkBothOperationIdAndRef()->checkLink($link, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    'only operationId'  => ['foo.show', null],
    'only operationRef' => [null, '#/paths/~1foo/get'],
    'neither'           => [null, null],
]);

it('emits a finding when a link has both operationId and operationRef', function (): void {
    $link = makeLinkBothFieldsNode('foo.show', '#/paths/~1foo/get');

    $findings = iterator_to_array(
        new LinkBothOperationIdAndRef()->checkLink($link, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('link.both-operation-id-and-ref')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('both')
        ->and($findings[0]->message)->toContain('foo.show');
});
