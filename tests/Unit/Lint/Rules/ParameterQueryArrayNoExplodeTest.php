<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ParameterQueryArrayNoExplode;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeQueryParamForArrayTest(
    string $name,
    ?string $type,
    ?string $style = null,
    ?bool $explode = null,
): QueryParameterNode {
    $queryParameter = OperationNodeFactory::makeQueryParameter(
        name: $name,
        type: $type,
        style: $style,
        explode: $explode,
    );

    OperationNodeFactory::makeOperation(
        pathUri: '/items',
        queryParameters: [$queryParameter],
    );

    return $queryParameter;
}

it('reports its id and level', function (): void {
    $rule = new ParameterQueryArrayNoExplode();

    expect($rule->id())
        ->toBe('parameter.query-array-no-explode')
        ->and($rule->severity())->toBe(Severity::Degraded);
});

it('emits no finding when an array query parameter declares serialization or is not an array', function (
    ?string $type,
    ?string $style,
    ?bool $explode,
): void {
    $rule = new ParameterQueryArrayNoExplode();
    $queryParameter = makeQueryParamForArrayTest('ids', type: $type, style: $style, explode: $explode);
    $findings = iterator_to_array(
        $rule->checkQueryParameter(
            $queryParameter,
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)->toBe([]);
})->with([
    'array with explode set' => ['array', null, true],
    'array with style set' => ['array', 'form', null],
    'non-array (string)' => ['string', null, null],
    'null type' => [null, null, null],
]);

it('emits a finding when a query array parameter lacks both style and explode', function (): void {
    $rule = new ParameterQueryArrayNoExplode();
    $queryParameter = makeQueryParamForArrayTest('ids', type: 'array');
    $findings = iterator_to_array(
        $rule->checkQueryParameter(
            $queryParameter,
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.query-array-no-explode')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->message)->toContain('ids')
        ->and($findings[0]->message)->toContain('style')
        ->and($findings[0]->message)->toContain('explode');
});
