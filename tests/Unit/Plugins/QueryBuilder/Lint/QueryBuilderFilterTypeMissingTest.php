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
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderFilterTypeMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use ReflectionClass;
use ReflectionMethod;

uses()->group('openapi', 'plugin:query-builder');

class FilterTypeLintController
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('mystery')]
    public function index(): void {}
}

it('flags an #[AllowedFilter] declared without a type', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new ReflectionClass(FilterTypeLintController::class),
        method: new ReflectionMethod(FilterTypeLintController::class, 'index'),
        summary: null,
        description: null,
    );

    $rule = new QueryBuilderFilterTypeMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('query-builder.filter-type-missing')
        ->and($findings[0]->message)->toContain('mystery');
});
