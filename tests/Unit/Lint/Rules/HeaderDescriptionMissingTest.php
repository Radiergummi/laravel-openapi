<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\HeaderDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeHeaderUnderResponse(string $name, ?string $description): HeaderNode
{
    $header = new HeaderNode(
        name: $name,
        schema: 'string',
        description: $description,
        required: false,
        raw: null,
    );

    $response = OperationNodeFactory::makeResponse(headers: [$header]);
    OperationNodeFactory::makeOperation(responses: [$response]);
    $header->linkParent($response);

    return $header;
}

it('has the correct rule id and level', function (): void {
    $rule = new HeaderDescriptionMissing();

    expect($rule->id())->toBe('header.description-missing')->and($rule->level())->toBe(2);
});

it('emits a finding when a response header has a missing or blank description', function (?string $description): void {
    $rule = new HeaderDescriptionMissing();
    $header = makeHeaderUnderResponse('X-Request-Id', $description);

    $findings = iterator_to_array(
        $rule->checkHeader($header, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('header.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('X-Request-Id');
})->with([
    'null'            => [null],
    'empty string'    => [''],
    'whitespace only' => ['   '],
]);

it('emits no findings when a header has a description', function (): void {
    $rule = new HeaderDescriptionMissing();
    $header = makeHeaderUnderResponse('X-Request-Id', 'Unique request identifier');

    $findings = iterator_to_array(
        $rule->checkHeader($header, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
