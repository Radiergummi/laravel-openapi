<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeParamNamingContext(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

function makePathParam(string $name): ParameterNode
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

function makeQueryParam(string $name): QueryParameterNode
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

// --- Path parameters ---

it('default (snake): passes a valid snake_case path parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkParameter(makePathParam('project_id'), $context));

    expect($findings)->toBe([]);
});

it('default (snake): passes a single-word path parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkParameter(makePathParam('project'), $context));

    expect($findings)->toBe([]);
});

it('default (snake): flags a camelCase path parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkParameter(makePathParam('projectId'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('projectId')
        ->and($findings[0]->message)->toContain('snake_case');
});

// --- Query parameters ---

it('default (snake): passes a valid snake_case query parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('created_after'), $context));

    expect($findings)->toBe([]);
});

it('default (snake): flags a camelCase query parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('createdAfter'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('createdAfter');
});

// --- Query parameter exclusions ---

it('skips bracket-notation query parameters (filter[id])', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('filter[id]'), $context));

    expect($findings)->toBe([]);
});

it('skips bracket-notation query parameters (page[number])', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('page[number]'), $context));

    expect($findings)->toBe([]);
});

it('skips the reserved "page" query parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('page'), $context));

    expect($findings)->toBe([]);
});

it('skips the reserved "per_page" query parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('per_page'), $context));

    expect($findings)->toBe([]);
});

it('skips the reserved "sort" query parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('sort'), $context));

    expect($findings)->toBe([]);
});

it('skips the reserved "include" query parameter', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('include'), $context));

    expect($findings)->toBe([]);
});

it('does not skip a non-reserved name that happens to contain "page" as a substring', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    // "myPage" is not in the exclusion list and is not snake_case
    $findings = iterator_to_array($rule->checkQueryParameter(makeQueryParam('myPage'), $context));

    expect($findings)->toHaveCount(1);
});

// --- Configurable case ---

it('camel case: passes a valid camelCase parameter name', function (): void {
    $rule = new ParameterNameNamingInconsistent(IdentifierCase::Camel);
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkParameter(makePathParam('projectId'), $context));

    expect($findings)->toBe([]);
});

it('camel case: flags a snake_case parameter name', function (): void {
    $rule = new ParameterNameNamingInconsistent(IdentifierCase::Camel);
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkParameter(makePathParam('project_id'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('camelCase');
});

it('provides a fix hint with the expected case example', function (): void {
    $rule = new ParameterNameNamingInconsistent();
    $context = makeParamNamingContext();

    $findings = iterator_to_array($rule->checkParameter(makePathParam('projectId'), $context));

    expect($findings[0]->fixHint)
        ->toContain('snake_case')
        ->toContain(IdentifierCase::Snake->example());
});
