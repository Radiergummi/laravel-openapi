<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldTypeMissing;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:api-resources');

#[ResourceField('id', type: 'integer')]
#[ResourceField('mystery')]
class TypelessFieldResource extends JsonResource {}

class TypelessFieldController
{
    public function show(): TypelessFieldResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

it('flags a #[ResourceField] with no type', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(TypelessFieldController::class, 'show', '/typeless');

    $rule = new ResourceFieldTypeMissing(ResourceClassLocator::create());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.field-type-missing')
        ->and($findings[0]->message)->toContain('mystery');
});
