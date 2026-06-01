<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:api-resources');

class BareLintResource extends JsonResource {}

class BareLintController
{
    public function show(): BareLintResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class AbstractReturnLintController
{
    public function returnsAbstract(): JsonResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

abstract class AbstractLintResourceSubclass extends JsonResource {}

class AbstractSubclassReturnLintController
{
    public function returnsAbstractSubclass(): AbstractLintResourceSubclass
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

it('flags a resource response whose class declares no #[ResourceField]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(BareLintController::class, 'show', '/bare');

    $rule = new ResourceFieldsUndeclared(new ResourceClassLocator());
    $node = OperationNodeFactory::forDescriptor($descriptor);

    $findings = iterator_to_array($rule->checkOperation($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.fields-undeclared');
});

it('does not fire when the action returns the abstract JsonResource base', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(AbstractReturnLintController::class, 'returnsAbstract', '/abstract');

    $rule = new ResourceFieldsUndeclared(new ResourceClassLocator());
    $node = OperationNodeFactory::forDescriptor($descriptor);

    $findings = iterator_to_array($rule->checkOperation($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('does not fire when the action returns an abstract JsonResource subclass', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(AbstractSubclassReturnLintController::class, 'returnsAbstractSubclass', '/abstract-sub');

    $rule = new ResourceFieldsUndeclared(new ResourceClassLocator());
    $node = OperationNodeFactory::forDescriptor($descriptor);

    $findings = iterator_to_array($rule->checkOperation($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});
