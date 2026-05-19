<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceResponseAmbiguous;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use ReflectionClass;
use ReflectionMethod;

uses()->group('openapi', 'plugin:api-resources');

class AmbiguousLintCollection extends ResourceCollection {}

class AmbiguousLintController
{
    public function index(): AmbiguousLintCollection
    { /** @phpstan-ignore-next-line */ return new AmbiguousLintCollection([]);
    }
}

it('flags a collection return type with no #[ResponseResource]', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/ambiguous', []),
        controller: new ReflectionClass(AmbiguousLintController::class),
        method: new ReflectionMethod(AmbiguousLintController::class, 'index'),
        summary: null,
        description: null,
    );

    $rule = new ResourceResponseAmbiguous(new ResourceClassLocator());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.response-ambiguous');
});
