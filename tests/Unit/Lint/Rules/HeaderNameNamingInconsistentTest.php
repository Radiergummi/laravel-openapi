<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\HeaderNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeHeaderNamingContext(): LintContext
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

function makeHeaderNamingNode(string $name): HeaderNode
{
    return new HeaderNode(
        name: $name,
        schema: 'string',
        description: null,
        required: false,
        raw: null,
    );
}

it('reports its id and level', function (): void {
    $rule = new HeaderNameNamingInconsistent();

    expect($rule->id())->toBe('header.name-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

it('default (train): passes a valid Train-Case header name', function (): void {
    $rule = new HeaderNameNamingInconsistent();
    $context = makeHeaderNamingContext();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('X-Request-Id'), $context));

    expect($findings)->toBe([]);
});

it('default (train): passes a single-segment Train-Case header', function (): void {
    $rule = new HeaderNameNamingInconsistent();
    $context = makeHeaderNamingContext();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('Authorization'), $context));

    expect($findings)->toBe([]);
});

it('default (train): flags a lowercase header name', function (): void {
    $rule = new HeaderNameNamingInconsistent();
    $context = makeHeaderNamingContext();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('x-request-id'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('header.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('"x-request-id"')
        ->and($findings[0]->message)->toContain('Train-Case');
});

it('default (train): flags a snake_case header name', function (): void {
    $rule = new HeaderNameNamingInconsistent();
    $context = makeHeaderNamingContext();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('x_request_id'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"x_request_id"');
});

it('provides a fix hint with the case label and example', function (): void {
    $rule = new HeaderNameNamingInconsistent();
    $context = makeHeaderNamingContext();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('x-request-id'), $context));

    expect($findings[0]->fixHint)
        ->toContain('Train-Case')
        ->toContain(IdentifierCase::Train->example());
});

it('pascal case: passes a valid PascalCase header name', function (): void {
    $rule = new HeaderNameNamingInconsistent(IdentifierCase::Pascal);
    $context = makeHeaderNamingContext();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('Authorization'), $context));

    expect($findings)->toBe([]);
});

it('pascal case: flags a Train-Case header name', function (): void {
    $rule = new HeaderNameNamingInconsistent(IdentifierCase::Pascal);
    $context = makeHeaderNamingContext();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('X-Request-Id'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('PascalCase');
});
