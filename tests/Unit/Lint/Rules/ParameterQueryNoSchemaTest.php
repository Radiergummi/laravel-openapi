<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\ParameterQueryNoSchema;
use Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode;
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
    $qp = OperationNodeFactory::makeQueryParameter(
        name: $name,
        type: $type,
        hasSchema: $hasSchema,
        enum: $enum,
    );

    OperationNodeFactory::makeOperation(
        pathUri: $pathUri,
        queryParameters: [$qp],
    );

    return $qp;
}

it('reports its id and level', function (): void {
    $rule = new ParameterQueryNoSchema();

    expect($rule->id())->toBe('parameter.query-no-schema')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when a query parameter has a schema', function (?string $type, bool $hasSchema, ?array $enum): void {
    $rule = new ParameterQueryNoSchema();
    $qp = makeQueryParamForSchemaTest(name: 'filter', type: $type, hasSchema: $hasSchema, enum: $enum);

    $findings = iterator_to_array($rule->checkQueryParameter($qp, OperationNodeFactory::emptyContext()));

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
    $qp = makeQueryParamForSchemaTest(name: 'filter', type: null, hasSchema: false);

    $findings = iterator_to_array($rule->checkQueryParameter($qp, OperationNodeFactory::emptyContext()));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.query-no-schema')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('filter')
        ->and($findings[0]->message)->toContain('no schema')
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users');
});

it('emits a finding per query parameter without schema', function (): void {
    $rule = new ParameterQueryNoSchema();
    $context = OperationNodeFactory::emptyContext();

    $qpA = makeQueryParamForSchemaTest(name: 'filter', type: null, hasSchema: false);
    $qpB = makeQueryParamForSchemaTest(name: 'sort', type: null, hasSchema: false);

    $findings = [
        ...iterator_to_array($rule->checkQueryParameter($qpA, $context)),
        ...iterator_to_array($rule->checkQueryParameter($qpB, $context)),
    ];

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)->toContain('filter')
        ->and($findings[1]->message)->toContain('sort');
});
