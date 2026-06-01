<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Lint;

use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderFilterTypeMissing;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:query-builder');

class FilterTypeLintController
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('mystery')]
    public function index(): void {}
}

it('flags an #[AllowedFilter] declared without a type', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(FilterTypeLintController::class, 'index');

    $rule = new QueryBuilderFilterTypeMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('query-builder.filter-type-missing')
        ->and($findings[0]->message)->toContain('mystery');
});
