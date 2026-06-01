<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Lint;

use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderParamsUndeclared;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use Spatie\QueryBuilder\QueryBuilder;
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
    public function undeclared(QueryBuilder $query): void {}

    #[AllowedFilter('status', type: 'string')]
    public function declared(QueryBuilder $query): void {}
}

it('flags a method injecting QueryBuilder with no query-builder attributes', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(ParamsUndeclaredController::class, 'undeclared');

    $rule = new QueryBuilderParamsUndeclared(new PayloadParameterScanner());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('query-builder.params-undeclared');
});

it('does not flag a method that declares query-builder attributes', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(ParamsUndeclaredController::class, 'declared');

    $rule = new QueryBuilderParamsUndeclared(new PayloadParameterScanner());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});
