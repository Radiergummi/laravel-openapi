<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceResponseEmpty;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use Radiergummi\OpenApi\Tests\Support\SchemaFromResourceFactory;

uses()->group('openapi', 'plugin:api-resources');

class EmptyResponseBaseController
{
    public function show(): JsonResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

abstract class EmptyResponseAbstractResource extends JsonResource {}

class EmptyResponseAbstractController
{
    public function show(): EmptyResponseAbstractResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class EmptyResponseConcreteResource extends JsonResource {}

class EmptyResponseConcreteController
{
    public function show(): EmptyResponseConcreteResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

function resourceResponseEmptyRule(): ResourceResponseEmpty
{
    return new ResourceResponseEmpty(
        ResourceClassLocator::create(),
        SchemaFromResourceFactory::toArrayReader(),
        SchemaFromResourceFactory::wrappedModelLocator(),
    );
}

/**
 * @param class-string $controller
 *
 * @return list<\Radiergummi\OpenApi\Lint\Finding>
 */
function resourceResponseEmptyFindings(string $controller, string $method, string $uri): array
{
    $descriptor = ActionDescriptorFactory::forControllerMethod($controller, $method, $uri);
    $node = OperationNodeFactory::forDescriptor($descriptor);

    return iterator_to_array(
        resourceResponseEmptyRule()->checkOperation($node, OperationNodeFactory::emptyContext()),
    );
}

it('flags a response that resolves to the base JsonResource — schema is empty', function (): void {
    $findings = resourceResponseEmptyFindings(EmptyResponseBaseController::class, 'show', '/base');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.response-empty')
        ->and($findings[0]->level)->toBe(1);
});

it('flags a response that resolves to an empty abstract JsonResource subclass', function (): void {
    $findings = resourceResponseEmptyFindings(EmptyResponseAbstractController::class, 'show', '/abstract');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.response-empty');
});

it('does not fire for a concrete resource — that is resource.fields-undeclared territory', function (): void {
    $findings = resourceResponseEmptyFindings(EmptyResponseConcreteController::class, 'show', '/concrete');

    expect($findings)->toBe([]);
});
