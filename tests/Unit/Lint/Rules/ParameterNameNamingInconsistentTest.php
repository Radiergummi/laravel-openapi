<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makePathParamNamingNode(string $name): ParameterNode
{
    return new ParameterNode(
        name: $name,
        required: true,
        schema: 'string',
        description: null,
        pattern: null,
        examples: [],
        raw: null,
    );
}

function makeQueryParamNamingNode(string $name): QueryParameterNode
{
    return new QueryParameterNode(
        name: $name,
        required: false,
        type: 'string',
        hasSchema: true,
        style: null,
        explode: null,
        description: null,
        enum: null,
        examples: [],
        raw: null,
    );
}

it('reports its id and level', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    expect($rule->id())->toBe('parameter.name-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

// region Path parameters

it('default (snake): passes a valid snake_case path parameter', function (string $name): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkParameter(makePathParamNamingNode($name), OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'multi-word snake_case' => ['project_id'],
    'single-word'           => ['project'],
]);

it('default (snake): flags a camelCase path parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkParameter(makePathParamNamingNode('projectId'), OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('projectId')
        ->and($findings[0]->message)->toContain('snake_case');
});

// endregion

// region Query parameters

it('default (snake): passes a valid snake_case query parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParamNamingNode('created_after'), OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('default (snake): flags a camelCase query parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParamNamingNode('createdAfter'), OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('createdAfter');
});

it('skips reserved or bracket-notation query parameter names', function (string $name): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParamNamingNode($name), OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'bracket filter[id]'     => ['filter[id]'],
    'bracket page[number]'   => ['page[number]'],
    'reserved page'          => ['page'],
    'reserved per_page'      => ['per_page'],
    'reserved sort'          => ['sort'],
    'reserved include'      => ['include'],
]);

it('does not skip a non-reserved name that happens to contain "page" as a substring', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    // "myPage" is not in the exclusion list and is not snake_case
    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParamNamingNode('myPage'), OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1);
});

// endregion

// region Configurable case

it('camel case: passes a valid camelCase parameter name', function (): void {
    $rule = new ParameterNameNamingInconsistent(IdentifierCase::Camel);

    $findings = iterator_to_array($rule->checkParameter(makePathParamNamingNode('projectId'), OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('camel case: flags a snake_case parameter name', function (): void {
    $rule = new ParameterNameNamingInconsistent(IdentifierCase::Camel);

    $findings = iterator_to_array($rule->checkParameter(makePathParamNamingNode('project_id'), OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('camelCase');
});

it('provides a fix hint with the expected case example', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkParameter(makePathParamNamingNode('projectId'), OperationNodeFactory::emptyContext()));

    expect($findings[0]->fixHint)
        ->toContain('snake_case')
        ->toContain(IdentifierCase::Snake->example());
});

// endregion
