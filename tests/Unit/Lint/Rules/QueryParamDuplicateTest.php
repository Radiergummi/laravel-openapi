<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\QueryParamDuplicate;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @param list<string> $queryParamNames
 */
function makeOperationWithQueryParams(array $queryParamNames): OperationNode
{
    return OperationNodeFactory::makeOperation(
        pathUri: '/fixture',
        queryParameters: array_map(
            static fn(string $name) => OperationNodeFactory::makeQueryParameter(name: $name),
            $queryParamNames,
        ),
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new QueryParamDuplicate();

    expect($rule->id())->toBe('queryparam.duplicate')
        ->and($rule->level())->toBe(0);
});

it('emits no findings when query param names are unique or absent', function (array $names): void {
    $rule = new QueryParamDuplicate();
    $operation = makeOperationWithQueryParams($names);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'no params' => [[]],
    'all unique' => [['q', 'limit', 'offset']],
]);

it('emits a finding when a method has duplicate query param names', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeOperationWithQueryParams(['q', 'q', 'limit']);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('queryparam.duplicate')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('"q"')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits one finding per duplicate group', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeOperationWithQueryParams(['q', 'q', 'filter', 'filter']);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('"q"')
        ->and($findings[1]->message)->toContain('"filter"');
});
