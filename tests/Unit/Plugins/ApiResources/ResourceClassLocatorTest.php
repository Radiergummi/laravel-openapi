<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use LogicException;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use stdClass;

class LocatorFixtureResource extends JsonResource {}
class LocatorFixtureCollection extends ResourceCollection {}

class LocatorFixtureController
{
    public function single(): LocatorFixtureResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function collectionType(): LocatorFixtureCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    #[ResponseResource(LocatorFixtureResource::class, collection: true)]
    public function attributed(): LocatorFixtureCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function notAResource(): string
    {
        return '';
    }

    /** @phpstan-ignore-next-line */
    #[ResponseResource(stdClass::class)]
    public function attributedNonResource(): string
    {
        return '';
    }
}

function locatorDescriptor(string $method): ActionDescriptor
{
    return ActionDescriptorFactory::forControllerMethod(LocatorFixtureController::class, $method);
}

it('locates a single resource from the return type', function (): void {
    $target = new ResourceClassLocator()->locate(locatorDescriptor('single'));

    expect($target?->resourceClass)->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('returns an ambiguous target for a bare collection return type', function (): void {
    $target = new ResourceClassLocator()->locate(locatorDescriptor('collectionType'));

    expect($target)->not->toBeNull()
        ->and($target?->isAmbiguous())->toBeTrue()
        ->and($target?->isCollection)->toBeTrue();
});

it('resolves the item class from a #[ResponseResource] attribute', function (): void {
    $target = new ResourceClassLocator()->locate(locatorDescriptor('attributed'));

    expect($target?->resourceClass)->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('returns null when the action does not return a resource', function (): void {
    expect(new ResourceClassLocator()->locate(locatorDescriptor('notAResource')))->toBeNull();
});

it('returns null when #[ResponseResource] names a non-JsonResource class', function (): void {
    expect(new ResourceClassLocator()->locate(locatorDescriptor('attributedNonResource')))->toBeNull();
});
