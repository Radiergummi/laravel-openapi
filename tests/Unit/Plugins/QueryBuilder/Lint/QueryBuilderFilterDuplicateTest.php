<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Lint;

use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderFilterDuplicate;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:query-builder');

class FilterDuplicateLintController
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('status', type: 'integer')]
    public function duplicate(): void {}

    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('status', type: 'integer')]
    #[AllowedFilter('status', type: 'boolean')]
    public function triplicate(): void {}

    #[AllowedFilter('name', type: 'string')]
    #[AllowedFilter('status', type: 'integer')]
    public function distinct(): void {}

    #[AllowedFilter('status', type: 'string')]
    public function single(): void {}
}

it('flags two #[AllowedFilter] with the same name', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(FilterDuplicateLintController::class, 'duplicate');

    $rule = new QueryBuilderFilterDuplicate();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('query-builder.filter-duplicate')
        ->and($findings[0]->message)->toContain('status');
});

it('emits one finding per duplicated name, not per extra instance', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(FilterDuplicateLintController::class, 'triplicate');

    $rule = new QueryBuilderFilterDuplicate();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('status');
});

it('emits no finding when all filter names are distinct', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(FilterDuplicateLintController::class, 'distinct');

    $rule = new QueryBuilderFilterDuplicate();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBeEmpty();
});

it('emits no finding for a single filter', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(FilterDuplicateLintController::class, 'single');

    $rule = new QueryBuilderFilterDuplicate();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBeEmpty();
});
