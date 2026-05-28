<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\Rules\HeaderNameNamingInconsistent;
use Radiergummi\OpenApi\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

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

it('default (train): passes a valid Train-Case header name', function (string $name): void {
    $rule = new HeaderNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode($name), OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'multi-segment'  => ['X-Request-Id'],
    'single-segment' => ['Authorization'],
]);

it('default (train): flags non-Train-Case header names', function (string $name): void {
    $rule = new HeaderNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode($name), OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('header.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain("\"{$name}\"")
        ->and($findings[0]->message)->toContain('Train-Case');
})->with([
    'lowercase'  => ['x-request-id'],
    'snake_case' => ['x_request_id'],
]);

it('provides a fix hint with the case label and example', function (): void {
    $rule = new HeaderNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('x-request-id'), OperationNodeFactory::emptyContext()));

    expect($findings[0]->fixHint)
        ->toContain('Train-Case')
        ->toContain(IdentifierCase::Train->example());
});

it('pascal case: passes a valid PascalCase header name', function (): void {
    $rule = new HeaderNameNamingInconsistent(IdentifierCase::Pascal);

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('Authorization'), OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('pascal case: flags a Train-Case header name', function (): void {
    $rule = new HeaderNameNamingInconsistent(IdentifierCase::Pascal);

    $findings = iterator_to_array($rule->checkHeader(makeHeaderNamingNode('X-Request-Id'), OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('PascalCase');
});
