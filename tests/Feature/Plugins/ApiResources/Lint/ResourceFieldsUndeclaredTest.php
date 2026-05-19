<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use ReflectionClass;
use ReflectionMethod;

uses()->group('openapi', 'plugin:api-resources');

class BareLintResource extends JsonResource {}

class BareLintController
{
    public function show(): BareLintResource
    { /** @phpstan-ignore-next-line */ return new BareLintResource(null);
    }
}

it('flags a resource response whose class declares no #[ResourceField]', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/bare', []),
        controller: new ReflectionClass(BareLintController::class),
        method: new ReflectionMethod(BareLintController::class, 'show'),
        summary: null,
        description: null,
    );

    $rule = new ResourceFieldsUndeclared(new ResourceClassLocator());
    $node = OperationNodeFactory::forDescriptor($descriptor);

    $findings = iterator_to_array($rule->checkOperation($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.fields-undeclared');
});
