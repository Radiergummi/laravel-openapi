<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use ReflectionClass;
use ReflectionMethod;

class LocatorFixtureResource extends JsonResource {}
class LocatorFixtureCollection extends ResourceCollection {}

class LocatorFixtureController
{
    public function single(): LocatorFixtureResource
    { /** @phpstan-ignore-next-line */ return new LocatorFixtureResource(null);
    }

    public function collectionType(): LocatorFixtureCollection
    { /** @phpstan-ignore-next-line */ return new LocatorFixtureCollection([]);
    }

    #[ResponseResource(LocatorFixtureResource::class, collection: true)]
    public function attributed(): LocatorFixtureCollection
    { /** @phpstan-ignore-next-line */ return new LocatorFixtureCollection([]);
    }

    public function notAResource(): string
    {
        return '';
    }
}

function locatorDescriptor(string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new ReflectionClass(LocatorFixtureController::class),
        method: new ReflectionMethod(LocatorFixtureController::class, $method),
        summary: null,
        description: null,
    );
}

it('locates a single resource from the return type', function (): void {
    $target = (new ResourceClassLocator())->locate(locatorDescriptor('single'));

    expect($target?->resourceClass)->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('returns an ambiguous target for a bare collection return type', function (): void {
    $target = (new ResourceClassLocator())->locate(locatorDescriptor('collectionType'));

    expect($target)->not->toBeNull()
        ->and($target?->isAmbiguous())->toBeTrue()
        ->and($target?->isCollection)->toBeTrue();
});

it('resolves the item class from a #[ResponseResource] attribute', function (): void {
    $target = (new ResourceClassLocator())->locate(locatorDescriptor('attributed'));

    expect($target?->resourceClass)->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('returns null when the action does not return a resource', function (): void {
    expect((new ResourceClassLocator())->locate(locatorDescriptor('notAResource')))->toBeNull();
});
