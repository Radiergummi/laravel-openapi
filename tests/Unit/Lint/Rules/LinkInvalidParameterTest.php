<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\LinkInvalidParameter;
use Radiergummi\OpenApi\Core\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @param array<string, string> $parameters
 */
function makeLinkInvalidParamNode(?string $operationId, array $parameters): LinkNode
{
    $link = OperationNodeFactory::makeLink(
        name: 'TestLink',
        operationId: $operationId,
        parameters: $parameters,
    );
    OperationNodeFactory::makeOperation(
        method: 'POST',
        responses: [OperationNodeFactory::makeResponse(statusCode: 201, description: null, links: [$link])],
    );

    return $link;
}

/**
 * @param list<string> $pathParams
 * @param list<string> $queryParams
 */
function makeLinkInvalidParamContext(
    string $targetOperationId,
    array $pathParams,
    array $queryParams,
): LintContext {
    $targetOp = OperationNodeFactory::makeOperation(
        pathUri: '/target',
        operationId: $targetOperationId,
        parameters: array_map(
            static fn(string $name) => OperationNodeFactory::makeParameter(name: $name, schema: null),
            $pathParams,
        ),
        queryParameters: array_map(
            static fn(string $name) => OperationNodeFactory::makeQueryParameter(name: $name, type: null, hasSchema: false),
            $queryParams,
        ),
    );

    return OperationNodeFactory::emptyContext(
        operationsByOperationId: [$targetOperationId => $targetOp],
    );
}

it('reports its id and level', function (): void {
    $rule = new LinkInvalidParameter();

    expect($rule->id())->toBe('link.invalid-parameter')->and($rule->level())->toBe(0);
});

it(
    'emits no finding when all link parameters are accepted by the target operation',
    function (): void {
        $link = makeLinkInvalidParamNode(
            operationId: 'foo.show',
            parameters: ['id' => '$response.body#/id'],
        );
        $context = makeLinkInvalidParamContext(
            targetOperationId: 'foo.show',
            pathParams: ['id'],
            queryParams: [],
        );

        $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

        expect($findings)->toBe([]);
    },
);

it(
    'emits a finding when a link parameter is not accepted by the target operation',
    function (): void {
        $link = makeLinkInvalidParamNode(
            operationId: 'foo.show',
            parameters: ['id' => '$response.body#/id', 'unknown' => 'value'],
        );
        $context = makeLinkInvalidParamContext(
            targetOperationId: 'foo.show',
            pathParams: ['id'],
            queryParams: [],
        );

        $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

        expect($findings)
            ->toHaveCount(1)
            ->and($findings[0]->ruleId)->toBe('link.invalid-parameter')
            ->and($findings[0]->level)->toBe(0)
            ->and($findings[0]->message)->toContain('unknown');
    },
);

it('emits a finding per invalid parameter', function (): void {
    $link = makeLinkInvalidParamNode(
        operationId: 'foo.show',
        parameters: ['bad1' => 'x', 'id' => 'y', 'bad2' => 'z'],
    );
    $context = makeLinkInvalidParamContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)->toContain('bad1')
        ->and($findings[1]->message)->toContain('bad2');
});

it('accepts both path and query parameters', function (): void {
    $link = makeLinkInvalidParamNode(
        operationId: 'foo.show',
        parameters: ['id' => 'x', 'filter' => 'y'],
    );
    $context = makeLinkInvalidParamContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: ['filter'],
    );

    $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('skips links without a resolvable target', function (?string $operationId, string $targetId): void {
    // Either the link has no operationId at all (the `operationRef` path the rule does not check),
    // or the target operationId is not registered in the spec.
    $link = makeLinkInvalidParamNode(operationId: $operationId, parameters: ['nonexistent' => 'value']);
    $context = makeLinkInvalidParamContext(
        targetOperationId: $targetId,
        pathParams: [],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

    expect($findings)->toBe([]);
})->with([
    'no operationId on link'    => [null, 'foo.show'],
    'unknown target operationId' => ['nonexistent', 'different.operation'],
]);
