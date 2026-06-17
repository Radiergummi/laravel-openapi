<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ReturnExpressionResourceReader;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\NestedAuthorResource;
use ReflectionMethod;

use function array_filter;
use function class_exists;
use function str_contains;

uses()->group('openapi', 'plugin:api-resources');

#[UseResource(NestedAuthorResource::class)]
class ReaderFixtureCuratedAuthor extends Model
{
    protected $guarded = [];
}

abstract class ReaderFixtureAbstractResource extends JsonResource {}

/**
 * Parse-only fixture; actions are never invoked.
 */
class ReaderFixtureController
{
    public function __construct(
        public Author $author,
    ) {}

    public function staticModelPaginate(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::paginate());
    }

    public function simplePaginated(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::query()->simplePaginate());
    }

    public function collectPaginated(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collect(Author::query()->paginate());
    }

    public function collectUnpaginated(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collect(Author::all());
    }

    public function baseClassCollect(): AnonymousResourceCollection
    {
        return JsonResource::collect(Author::all());
    }

    public function abstractClassCollect(): AnonymousResourceCollection
    {
        return ReaderFixtureAbstractResource::collect(Author::all());
    }

    public function nonWhitelistedChain(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::all())->preserveQuery();
    }

    public function paginatedWithQueryString(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::query()->paginate(10)->withQueryString());
    }

    public function paginatedWithNonWhitelistedTrailingCall(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(
            Author::query()->paginate(10)->through(static fn(Author $author): Author => $author),
        );
    }

    public function baseClassCollection(): AnonymousResourceCollection
    {
        return JsonResource::collection(Author::all());
    }

    public function abstractClassCollection(): AnonymousResourceCollection
    {
        return ReaderFixtureAbstractResource::collection(Author::all());
    }

    /**
     * @return ResourceCollection<NestedAuthorResource>
     */
    public function docblockToResourceCollectionPaginated(): ResourceCollection
    {
        return Author::query()->paginate()->toResourceCollection(NestedAuthorResource::class);
    }

    /**
     * @return AnonymousResourceCollection<NestedAuthorResource>
     */
    public function docblockConditionalAssignment(bool $flag): AnonymousResourceCollection
    {
        if ($flag) {
            $collection = NestedAuthorResource::collection(Author::query()->paginate());
        } else {
            $collection = NestedAuthorResource::collection(Author::all());
        }

        return $collection;
    }

    public function propertyReceiverToResource(): JsonResource
    {
        return $this->author->toResource();
    }

    public function attributedModelToResource(ReaderFixtureCuratedAuthor $author): JsonResource
    {
        return $author->toResource();
    }

    public function wrappedNonModel(Request $request): JsonResource
    {
        return new JsonResource($request);
    }
}

function readerFor(?LoggerInterface $logger = null): ReturnExpressionResourceReader
{
    return ReturnExpressionResourceReader::create($logger);
}

function readerMethod(string $method): ReflectionMethod
{
    return new ReflectionMethod(ReaderFixtureController::class, $method);
}

it('marks a static Model::paginate() collection argument as paginated', function (): void {
    $target = readerFor()->read(readerMethod('staticModelPaginate'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue()
        ->and($target?->paginated)->toBeTrue();
});

it('marks a simplePaginate() collection argument as paginated', function (): void {
    $target = readerFor()->read(readerMethod('simplePaginated'));

    expect($target?->paginated)->toBeTrue();
});

it('looks through ->withQueryString() to keep the pagination evidence', function (): void {
    $target = readerFor()->read(readerMethod('paginatedWithQueryString'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue()
        ->and($target?->paginated)->toBeTrue();
});

it('falls back to the plain envelope for an item-mapping ->through() trailing call', function (): void {
    $target = readerFor()->read(readerMethod('paginatedWithNonWhitelistedTrailingCall'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->paginated)->toBeFalse();
});

it('resolves X::collect() to a paginated collection of the resource', function (): void {
    $target = readerFor()->read(readerMethod('collectPaginated'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue()
        ->and($target?->paginated)->toBeTrue();
});

it('marks an unpaginated X::collect() argument as not paginated', function (): void {
    $target = readerFor()->read(readerMethod('collectUnpaginated'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue()
        ->and($target?->paginated)->toBeFalse();
});

it('refuses a collect() call on the base JsonResource class with a note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('baseClassCollect'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'baseClassCollect'),
        ))->toHaveCount(1);
});

it('refuses a collect() call on an abstract resource subclass with a note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('abstractClassCollect'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'abstractClassCollect'),
        ))->toHaveCount(1);
});

it('refuses a collection call on the base JsonResource class with a note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('baseClassCollection'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'baseClassCollection'),
        ))->toHaveCount(1);
});

it('refuses a collection call on an abstract resource subclass with a note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('abstractClassCollection'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'abstractClassCollection'),
        ))->toHaveCount(1);
});

it('refuses a non-whitelisted chained call with a note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('nonWhitelistedChain'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'nonWhitelistedChain'),
        ))->toHaveCount(1);
});

it('marks a @return-docblock collection with ->toResourceCollection() on a paginating receiver as paginated', function (): void {
    $target = readerFor()->read(readerMethod('docblockToResourceCollectionPaginated'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue()
        ->and($target?->paginated)->toBeTrue();
});

it('emits no refusal notice when a @return-docblock collection has a conditionally assigned body', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('docblockConditionalAssignment'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'docblockConditionalAssignment'),
        ))->toBeEmpty();
});

it('refuses a bare ->toResource() whose receiver is not a typed parameter', function (): void {
    expect(readerFor()->read(readerMethod('propertyReceiverToResource')))->toBeNull();
});

it('resolves a bare ->toResource() through the model #[UseResource] attribute', function (): void {
    $target = readerFor()->read(readerMethod('attributedModelToResource'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
})->skip(
    fn(): bool => !class_exists(UseResource::class),
    'Requires Laravel\'s #[UseResource] model attribute.',
);

it('refuses new JsonResource() wrapping a non-Model parameter', function (): void {
    expect(readerFor()->read(readerMethod('wrappedNonModel')))->toBeNull();
});

it('memoises per method so the refusal note fires once per run', function (): void {
    $logger = recordingLogger();
    $reader = readerFor($logger);

    $reader->read(readerMethod('nonWhitelistedChain'));
    $reader->read(readerMethod('nonWhitelistedChain'));

    expect(array_filter(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'nonWhitelistedChain'),
    ))->toHaveCount(1);
});
