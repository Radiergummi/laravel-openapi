<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Lint;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderParamsUndeclared;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

uses()->group('openapi', 'plugin:query-builder');

/*
 * A stand-in for `Spatie\QueryBuilder\QueryBuilder`. The rule matches the type
 * name as a string, so the fixture method below declares the real FQCN via a
 * class_alias so the test does not require the package.
 */
if (!class_exists('Spatie\\QueryBuilder\\QueryBuilder')) {
    class_alias(stdClass::class, 'Spatie\\QueryBuilder\\QueryBuilder');
}

class ParamsUndeclaredController
{
    public function undeclared(\Spatie\QueryBuilder\QueryBuilder $query): void {}

    #[AllowedFilter('status', type: 'string')]
    public function declared(\Spatie\QueryBuilder\QueryBuilder $query): void {}
}

function paramsUndeclaredDescriptor(string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new ReflectionClass(ParamsUndeclaredController::class),
        method: new ReflectionMethod(ParamsUndeclaredController::class, $method),
        summary: null,
        description: null,
    );
}

it('flags a method injecting QueryBuilder with no query-builder attributes', function (): void {
    $rule = new QueryBuilderParamsUndeclared();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor(paramsUndeclaredDescriptor('undeclared')),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('query-builder.params-undeclared');
});

it('does not flag a method that declares query-builder attributes', function (): void {
    $rule = new QueryBuilderParamsUndeclared();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor(paramsUndeclaredDescriptor('declared')),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});
