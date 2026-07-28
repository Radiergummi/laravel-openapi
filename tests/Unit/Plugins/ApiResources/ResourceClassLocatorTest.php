<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use LogicException;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceClassLocator;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use stdClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

    public function untypedResource()
    {
        return LocatorFixtureResource::make(new stdClass());
    }

    public function untypedNonResource()
    {
        return response()->json(['ok' => true]);
    }

    // The loose-return idiom under test: a JsonResponse/Response-typed action that returns a resource
    // construction (framework-coerced at runtime). The declared-type mismatch is the point of the
    // fixture, so the return.type contradiction is intentionally ignored per file convention.

    public function jsonResponseMake(): JsonResponse
    {
        /** @phpstan-ignore return.type (deliberately loose: the body scan must read the resource construction) */
        return LocatorFixtureResource::make(new stdClass());
    }

    public function jsonResponseCollection(): JsonResponse
    {
        /** @phpstan-ignore return.type (deliberately loose: the body scan must read the resource construction) */
        return LocatorFixtureResource::collection([]);
    }

    public function jsonResponseNew(): JsonResponse
    {
        /** @phpstan-ignore return.type (deliberately loose: the body scan must read the resource construction) */
        return new LocatorFixtureResource(new stdClass());
    }

    public function illuminateResponseMake(): Response
    {
        /** @phpstan-ignore return.type (deliberately loose: the body scan must read the resource construction) */
        return LocatorFixtureResource::make(new stdClass());
    }

    public function symfonyResponseMake(): SymfonyResponse
    {
        /** @phpstan-ignore return.type (deliberately loose: the body scan must read the resource construction) */
        return LocatorFixtureResource::make(new stdClass());
    }

    public function jsonResponseNonResource(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function looseReturnWithAttribute(): JsonResponse
    {
        /** @phpstan-ignore return.type (deliberately loose: the body scan must read the resource construction) */
        return LocatorFixtureResource::make(new stdClass());
    }

    #[ResponseResource(LocatorFixtureResource::class)]
    public function looseReturnAttributed(): JsonResponse
    {
        /** @phpstan-ignore return.type (deliberately loose: the body scan must read the resource construction) */
        return LocatorFixtureResource::make(new stdClass());
    }

    #[ResponseResource(LocatorFixtureResource::class)]
    public function attributedForbiddenWrapper(): JsonResponse
    {
        return response()->json(LocatorFixtureResource::make(new stdClass()), 403);
    }
}

function locatorDescriptor(string $method): ActionDescriptor
{
    return ActionDescriptorFactory::forControllerMethod(LocatorFixtureController::class, $method);
}

it('locates a single resource from the return type', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('single'));

    expect($target?->resourceClass)
        ->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('returns an ambiguous target for a bare collection return type', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('collectionType'));

    expect($target)->not
        ->toBeNull()
        ->and($target?->isAmbiguous)->toBeTrue()
        ->and($target?->isCollection)->toBeTrue();
});

it('resolves the item class from a #[ResponseResource] attribute', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('attributed'));

    expect($target?->resourceClass)
        ->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('carries the wrapper status alongside the attribute-resolved resource', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('attributedForbiddenWrapper'));

    // The attribute keeps class and cardinality; the wrapper contributes only the status.
    expect($target?->resourceClass)
        ->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeFalse()
        ->and($target?->authoredStatus)->toBe(403);
});

it('leaves the authored status null for an attributed action with no return', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('attributed'));

    expect($target?->authoredStatus)->toBeNull();
});

it('returns null when the action does not return a resource', function (): void {
    expect(ResourceClassLocator::create()->locate(locatorDescriptor('notAResource')))->toBeNull();
});

it('returns null when #[ResponseResource] names a non-JsonResource class', function (): void {
    expect(ResourceClassLocator::create()->locate(locatorDescriptor('attributedNonResource')))->toBeNull();
});

it('resolves the item class from Laravels Collects attribute', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('attributeReturn'));

    expect($target)
        ->not
        ->toBeNull()
        ->and($target?->resourceClass)->toBe(W4CollectsItemResource::class)
        ->and($target?->isCollection)->toBeTrue();
})->skip(
    fn(): bool => !class_exists(Collects::class),
    'Requires Laravel\'s #[Collects] attribute (Laravel 13+).',
);

it('resolves the item class from a $collects property on the collection subclass', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('propertyReturn'));

    expect($target)
        ->not
        ->toBeNull()
        ->and($target?->resourceClass)->toBe(W4CollectsItemResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('still reports ambiguous when neither #[Collects] nor $collects is present', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('ambiguousReturn'));

    expect($target)
        ->not
        ->toBeNull()
        ->and($target?->resourceClass)->toBeNull()
        ->and($target?->isCollection)->toBeTrue();
});

it('resolves the resource from the body of an untyped action', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('untypedResource'));

    expect($target?->resourceClass)
        ->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('returns null for an untyped action that does not return a resource', function (): void {
    expect(ResourceClassLocator::create()->locate(locatorDescriptor('untypedNonResource')))->toBeNull();
});

// region GAP 1: loose response wrapper return types reach the body scan

it('resolves a resource from the body of a JsonResponse-typed action', function (string $method): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor($method));

    expect($target?->resourceClass)->toBe(LocatorFixtureResource::class);
})->with([
    'X::make()' => ['jsonResponseMake'],
    'new X()' => ['jsonResponseNew'],
]);

it('resolves a collection from the body of a JsonResponse-typed action', function (): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor('jsonResponseCollection'));

    expect($target?->resourceClass)
        ->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('resolves a resource for the parent Response / Symfony Response wrapper types', function (string $method): void {
    $target = ResourceClassLocator::create()->locate(locatorDescriptor($method));

    expect($target?->resourceClass)->toBe(LocatorFixtureResource::class);
})->with([
    'Illuminate\\Http\\Response' => ['illuminateResponseMake'],
    'Symfony Response' => ['symfonyResponseMake'],
]);

it('does not claim a non-resource JsonResponse return (no double-claim with the inline-JSON resolver)', function (): void {
    // A response()->json([...]) body under a JsonResponse type is Core's InlineJsonResponseResolver's
    // to claim; the ApiResources path must read back null so the two never both fire.
    expect(ResourceClassLocator::create()->locate(locatorDescriptor('jsonResponseNonResource')))->toBeNull();
});

// endregion

// region Attribute-retirement proof: the loose-return inference makes #[ResponseResource] redundant

it('infers the same resource with and without #[ResponseResource] on a loose JsonResponse return', function (): void {
    $withAttribute = ResourceClassLocator::create()->locate(locatorDescriptor('looseReturnAttributed'));
    $withoutAttribute = ResourceClassLocator::create()->locate(locatorDescriptor('looseReturnWithAttribute'));

    expect($withoutAttribute?->resourceClass)
        ->toBe(LocatorFixtureResource::class)
        ->and($withoutAttribute?->resourceClass)->toBe($withAttribute?->resourceClass)
        ->and($withoutAttribute?->isCollection)->toBe($withAttribute?->isCollection);
});

// endregion
