<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ParameterQueryNoSchema;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @param null|list<string> $enum
 */
function makeQueryParamForSchemaTest(
    string $name,
    ?string $type,
    bool $hasSchema = true,
    ?array $enum = null,
    string $pathUri = '/users',
): QueryParameterNode {
    $queryParameter = OperationNodeFactory::makeQueryParameter(
        name: $name,
        type: $type,
        hasSchema: $hasSchema,
        enum: $enum,
    );

    OperationNodeFactory::makeOperation(
        pathUri: $pathUri,
        queryParameters: [$queryParameter],
    );

    return $queryParameter;
}

it('reports its id and level', function (): void {
    $rule = new ParameterQueryNoSchema();

    expect($rule->id)
        ->toBe('parameter.query-no-schema')
        ->and($rule->severity)->toBe(Severity::Broken);
});

it('emits no finding when a query parameter has a schema', function (
    ?string $type,
    bool $hasSchema,
    ?array $enum,
): void {
    $rule = new ParameterQueryNoSchema();
    $queryParameter = makeQueryParamForSchemaTest(
        name: 'filter',
        type: $type,
        hasSchema: $hasSchema,
        enum: $enum,
    );

    $findings = iterator_to_array(
        $rule->checkQueryParameter(
            $queryParameter,
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)->toBe([]);
})->with([
    'string type' => ['string', true, null],
    'integer type' => ['integer', true, null],
    // Schema may exist with only enum/format/$ref and no explicit `type`;
    // hasSchema=true is the authoritative signal.
    'no type but hasSchema=true (enum only)' => [null, true, ['active', 'inactive']],
]);

it('emits a finding when a query parameter has no schema', function (): void {
    $rule = new ParameterQueryNoSchema();
    $queryParameter = makeQueryParamForSchemaTest(name: 'filter', type: null, hasSchema: false);

    $findings = iterator_to_array(
        $rule->checkQueryParameter(
            $queryParameter,
            OperationNodeFactory::emptyContext(),
        ),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.query-no-schema')
        ->and($findings[0]->severity)->toBe(Severity::Broken)
        ->and($findings[0]->message)->toContain('filter')
        ->and($findings[0]->message)->toContain('no schema')
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users');
});

it('emits a finding per query parameter without schema', function (): void {
    $rule = new ParameterQueryNoSchema();
    $context = OperationNodeFactory::emptyContext();

    $queryParameterA = makeQueryParamForSchemaTest(name: 'filter', type: null, hasSchema: false);
    $queryParameterB = makeQueryParamForSchemaTest(name: 'sort', type: null, hasSchema: false);

    $findings = [
        ...iterator_to_array($rule->checkQueryParameter($queryParameterA, $context)),
        ...iterator_to_array($rule->checkQueryParameter($queryParameterB, $context)),
    ];

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)->toContain('filter')
        ->and($findings[1]->message)->toContain('sort');
});
