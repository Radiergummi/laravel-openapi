<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use LogicException;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use stdClass;

class LocatorFixtureResource extends JsonResource {}
class LocatorFixtureCollection extends ResourceCollection {}

class W4CollectsItemResource extends JsonResource {}

#[Collects(W4CollectsItemResource::class)]
class W4CollectsAttributeCollection extends ResourceCollection {}

class W4CollectsPropertyCollection extends ResourceCollection
{
    /** @var class-string<JsonResource> */
    public $collects = W4CollectsItemResource::class;
}

class W4CollectsAmbiguousCollection extends ResourceCollection {}

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

    #[ResponseResource(stdClass::class)]
    public function attributedNonResource(): string
    {
        return '';
    }

    public function attributeReturn(): W4CollectsAttributeCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function propertyReturn(): W4CollectsPropertyCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function ambiguousReturn(): W4CollectsAmbiguousCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
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

it('resolves the item class from Laravels Collects attribute', function (): void {
    $target = new ResourceClassLocator()->locate(locatorDescriptor('attributeReturn'));

    expect($target)
        ->not->toBeNull()
        ->and($target?->resourceClass)->toBe(W4CollectsItemResource::class)
        ->and($target?->isCollection)->toBeTrue();
})->skip(
    fn(): bool => !class_exists(Collects::class),
    'Requires Laravel\'s #[Collects] attribute (Laravel 13+).',
);

it('resolves the item class from a $collects property on the collection subclass', function (): void {
    $target = new ResourceClassLocator()->locate(locatorDescriptor('propertyReturn'));

    expect($target)
        ->not->toBeNull()
        ->and($target?->resourceClass)->toBe(W4CollectsItemResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('still reports ambiguous when neither #[Collects] nor $collects is present', function (): void {
    $target = new ResourceClassLocator()->locate(locatorDescriptor('ambiguousReturn'));

    expect($target)
        ->not->toBeNull()
        ->and($target?->resourceClass)->toBeNull()
        ->and($target?->isCollection)->toBeTrue();
});
