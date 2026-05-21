<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\ParameterPathMustBeRequired;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makePathParameter(string $name, bool $required): ParameterNode
{
    $param = OperationNodeFactory::makeParameter(
        name: $name,
        required: $required,
        schema: 'integer',
    );

    OperationNodeFactory::makeOperation(
        pathUri: "/users/{{$name}}",
        parameters: [$param],
    );

    return $param;
}

it('reports its id and level', function (): void {
    $rule = new ParameterPathMustBeRequired();

    expect($rule->id())->toBe('parameter.path-must-be-required')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when a path parameter is required', function (): void {
    $rule = new ParameterPathMustBeRequired();
    $param = makePathParameter('userId', required: true);

    $findings = iterator_to_array($rule->checkParameter($param, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('emits a finding when a path parameter is not required', function (): void {
    $rule = new ParameterPathMustBeRequired();
    $param = makePathParameter('userId', required: false);

    $findings = iterator_to_array($rule->checkParameter($param, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.path-must-be-required')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('userId')
        ->and($findings[0]->message)->toContain('must be required');
});

it('emits one finding per non-required path parameter across operations', function (): void {
    $rule = new ParameterPathMustBeRequired();
    $context = OperationNodeFactory::emptyContext();

    $paramA = makePathParameter('userId', required: false);
    $paramB = makePathParameter('postId', required: false);

    $findings = [
        ...iterator_to_array($rule->checkParameter($paramA, $context)),
        ...iterator_to_array($rule->checkParameter($paramB, $context)),
    ];

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('userId')
        ->and($findings[1]->message)->toContain('postId');
});
