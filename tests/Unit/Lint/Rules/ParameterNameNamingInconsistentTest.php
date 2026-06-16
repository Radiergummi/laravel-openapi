<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\Rules\ParameterNameNamingInconsistent;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
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

    expect($rule->id())
        ->toBe('parameter.name-naming-inconsistent')
        ->and($rule->severity())->toBe(Severity::Inconsistent);
});

// region Path parameters

it('default (camel for path): accepts camelCase path parameters', function (string $name): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array(
        $rule->checkParameter(
            makePathParamNamingNode($name),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)->toBe([]);
})->with([
    'simple' => 'deviceId',
    'longer' => 'externalSearchQuery',
    'short' => 'threadId',
]);

it('default (camel for path): flags snake_case path parameters', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array(
        $rule->checkParameter(
            makePathParamNamingNode('device_id'),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.name-naming-inconsistent')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)->toContain('device_id')
        ->and($findings[0]->message)->toContain('camelCase');
});

// endregion

// region Query parameters

it('default (snake for query): accepts snake_case query parameters', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array(
        $rule->checkQueryParameter(
            makeQueryParamNamingNode('per_page'),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)->toBe([]);
});

it('default (snake for query): also accepts non-reserved snake_case query parameters', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array(
        $rule->checkQueryParameter(
            makeQueryParamNamingNode('created_after'),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)->toBe([]);
});

it('default (snake for query): flags camelCase query parameters', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array(
        $rule->checkQueryParameter(
            makeQueryParamNamingNode('perPage'),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.name-naming-inconsistent')
        ->and($findings[0]->message)->toContain('perPage')
        ->and($findings[0]->message)->toContain('snake_case');
});

it('skips reserved or bracket-notation query parameter names', function (string $name): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array(
        $rule->checkQueryParameter(makeQueryParamNamingNode($name), OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'bracket filter[id]' => ['filter[id]'],
    'bracket page[number]' => ['page[number]'],
    'reserved page' => ['page'],
    'reserved per_page' => ['per_page'],
    'reserved sort' => ['sort'],
    'reserved include' => ['include'],
]);

it('does not skip a non-reserved name that happens to contain "page" as a substring', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    // "myPage" is not in the exclusion list and is not snake_case
    $findings = iterator_to_array(
        $rule->checkQueryParameter(makeQueryParamNamingNode('myPage'), OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1);
});

// endregion

// region Configurable case

it('respects independent overrides for path and query case', function (): void {
    // Both forced to Snake — camelCase path param now flags.
    $rule = new ParameterNameNamingInconsistent(
        pathCase: IdentifierCase::Snake,
        queryCase: IdentifierCase::Snake,
    );

    $findings = iterator_to_array(
        $rule->checkParameter(
            makePathParamNamingNode('deviceId'),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)->toHaveCount(1);
});

it('path case override: flags camelCase path parameters when set to Snake', function (): void {
    $rule = new ParameterNameNamingInconsistent(pathCase: IdentifierCase::Snake);

    $findings = iterator_to_array(
        $rule->checkParameter(
            makePathParamNamingNode('projectId'),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)->toContain('snake_case');
});

it('query case override: flags snake_case query parameters when set to Camel', function (): void {
    $rule = new ParameterNameNamingInconsistent(queryCase: IdentifierCase::Camel);

    $findings = iterator_to_array(
        $rule->checkQueryParameter(
            makeQueryParamNamingNode('created_after'),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)->toContain('camelCase');
});

it('provides a fix hint with the expected case example', function (): void {
    $rule = new ParameterNameNamingInconsistent();

    $findings = iterator_to_array(
        $rule->checkParameter(
            makePathParamNamingNode('device_id'),
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings[0]->fixHint)
        ->toContain('camelCase')
        ->toContain(IdentifierCase::Camel->example());
});

// endregion
