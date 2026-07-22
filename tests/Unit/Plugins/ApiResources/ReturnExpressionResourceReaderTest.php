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
use Radiergummi\OpenApi\Tests\Fixtures\Http\Resources\AuthorResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\LiteralOnlyResource;
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

    public function methodReturnModelToResource(): JsonResource
    {
        return $this->findAuthor()->toResource();
    }

    public function findAuthor(): Author
    {
        return $this->author;
    }

    public function passthroughToResource(Author $author): JsonResource
    {
        return $this->reload($author)->toResource();
    }

    public function reload(Author $author): Model
    {
        return $author->refresh();
    }

    public function nonReturningPassthroughToResource(Author $author): JsonResource
    {
        return $this->currentActor($author)->toResource();
    }

    public function currentActor(Author $subject): Model
    {
        // Ignores its argument and returns an unrelated model — must NOT be treated as a passthrough.
        return new ReaderFixtureCuratedAuthor();
    }

    public function unresolvableReceiverToResource(): JsonResource
    {
        return $this->makeSomething()->toResource();
    }

    public function makeSomething(): Model
    {
        return $this->author;
    }

    public function localNewModelToResource(): JsonResource
    {
        $author = new Author();
        $author->save();

        return $author->toResource();
    }

    public function localStaticFactoryToResource(): JsonResource
    {
        $author = Author::create(['name' => 'x']);

        return $author->toResource();
    }

    public function assertNarrowedLocalToResource(): JsonResource
    {
        $author = $this->author->fresh();
        assert($author instanceof Author);

        return $author->toResource();
    }

    public function genericPassthroughToResource(Author $author): JsonResource
    {
        return $this->resolveGeneric($author)->toResource();
    }

    /**
     * A declared identity generic: the return is exactly the argument's type regardless of the
     * body's branches. `calleeReturnedParameterIndex()` cannot prove this (two returns), so only
     * the generic docblock read resolves it.
     *
     * @template TModel of Model
     *
     * @param TModel $model
     *
     * @return TModel
     */
    public function resolveGeneric(Model $model): Model
    {
        if ($model->getKey() === null) {
            return $model;
        }

        return $model;
    }

    public function genericListReturnToResource(Author $author): JsonResource
    {
        return $this->firstGeneric([$author])->toResource();
    }

    /**
     * The template rides in on a `list<TModel>` parameter, not a bare `TModel` one, so there is no
     * single argument whose type is the return — must refuse.
     *
     * @template TModel of Model
     *
     * @param list<TModel> $models
     *
     * @return TModel
     */
    public function firstGeneric(array $models): Model
    {
        return $models[0];
    }

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

    public function twoSameCollections(bool $flag): AnonymousResourceCollection
    {
        if ($flag) {
            return NestedAuthorResource::collection(Author::all());
        }

        return NestedAuthorResource::collection(Author::all());
    }

    public function twoSameMakes(bool $flag): JsonResource
    {
        if ($flag) {
            return NestedAuthorResource::make(Author::query()->firstOrFail());
        }

        return NestedAuthorResource::make(Author::query()->firstOrFail());
    }

    public function divergentResourceClass(bool $flag): JsonResource
    {
        if ($flag) {
            return NestedAuthorResource::make(Author::query()->firstOrFail());
        }

        return LiteralOnlyResource::make(Author::query()->firstOrFail());
    }

    public function divergentCardinality(bool $flag): JsonResource
    {
        if ($flag) {
            return NestedAuthorResource::collection(Author::all());
        }

        return NestedAuthorResource::make(Author::query()->firstOrFail());
    }

    public function divergentPagination(bool $flag): AnonymousResourceCollection
    {
        if ($flag) {
            return NestedAuthorResource::collection(Author::query()->paginate());
        }

        return NestedAuthorResource::collection(Author::all());
    }

    public function oneDynamicBranch(bool $flag): mixed
    {
        if ($flag) {
            return NestedAuthorResource::collection(Author::all());
        }

        return response()->json(['ok' => true]);
    }

    public function variableUnwrapBranches(bool $flag): AnonymousResourceCollection
    {
        $authors = NestedAuthorResource::collection(Author::all());

        if ($flag) {
            return $authors;
        }

        return NestedAuthorResource::collection(Author::all());
    }

    public function nullLiteralThenResource(bool $flag): ?AnonymousResourceCollection
    {
        if ($flag) {
            return null;
        }

        return NestedAuthorResource::collection(Author::all());
    }

    public function earlyBareReturnThenResource(bool $flag)
    {
        if ($flag) {
            return;
        }

        return NestedAuthorResource::collection(Author::all());
    }

    public function allBareOrNull(bool $flag)
    {
        if ($flag) {
            return;
        }

        return null;
    }

    public function mutatedAfterAssignment(): AnonymousResourceCollection
    {
        /** @var AnonymousResourceCollection $resources */
        $resources = NestedAuthorResource::collection(Author::all());
        $resources['extra'] = NestedAuthorResource::make(Author::query()->firstOrFail());

        return $resources;
    }

    public function conditionallyAssignedVariable(bool $flag): AnonymousResourceCollection
    {
        if ($flag) {
            $resources = NestedAuthorResource::collection(Author::query()->paginate());
        } else {
            $resources = NestedAuthorResource::collection(Author::all());
        }

        return $resources;
    }

    public function dynamicallyNamedVariable(string $name): AnonymousResourceCollection
    {
        $$name = NestedAuthorResource::collection(Author::all());

        return $$name;
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

it('refuses a conditionally-assigned returned variable with the not-assigned-once note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('conditionallyAssignedVariable'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains(
                $record['message'],
                'The return expression of ' . ReaderFixtureController::class . '::conditionallyAssignedVariable'
                . ' returns $resources, which is not assigned exactly once on the unconditional path;'
                . ' the concrete resource stays unresolved. Annotate the action with #[ResponseResource]'
                . ' to document the response.',
            ),
        ))->toHaveCount(1);
});

it('refuses a dynamically-named returned variable with the dynamic-variable note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('dynamicallyNamedVariable'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains(
                $record['message'],
                'The return expression of ' . ReaderFixtureController::class . '::dynamicallyNamedVariable'
                . ' returns a dynamically-named variable;'
                . ' the concrete resource stays unresolved. Annotate the action with #[ResponseResource]'
                . ' to document the response.',
            ),
        ))->toHaveCount(1);
});

it('refuses a returned variable mutated after its assignment with the mutation note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('mutatedAfterAssignment'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains(
                $record['message'],
                'The return expression of ' . ReaderFixtureController::class . '::mutatedAfterAssignment'
                . ' returns $resources, which is mutated after its single unconditional assignment;'
                . ' the concrete resource stays unresolved. Annotate the action with #[ResponseResource]'
                . ' to document the response.',
            ),
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

it('resolves a ->toResource() whose receiver is a Model-typed property', function (): void {
    $target = readerFor()->read(readerMethod('propertyReceiverToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('resolves a ->toResource() whose receiver is a method with a concrete Model return', function (): void {
    $target = readerFor()->read(readerMethod('methodReturnModelToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('resolves a ->toResource() through a base-Model passthrough call, from its argument', function (): void {
    $target = readerFor()->read(readerMethod('passthroughToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('refuses a base-Model return with no Model-typed argument to carry the type', function (): void {
    expect(readerFor()->read(readerMethod('unresolvableReceiverToResource')))->toBeNull();
});

it('refuses a base-Model call that does not return its argument (no false passthrough)', function (): void {
    expect(readerFor()->read(readerMethod('nonReturningPassthroughToResource')))->toBeNull();
});

it('resolves a ->toResource() through an identity @return T generic, from its argument', function (): void {
    $target = readerFor()->read(readerMethod('genericPassthroughToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('refuses an identity generic whose template rides in on a list<T> parameter', function (): void {
    expect(readerFor()->read(readerMethod('genericListReturnToResource')))->toBeNull();
});

it('resolves a ->toResource() on a local assigned from new Model()', function (): void {
    $target = readerFor()->read(readerMethod('localNewModelToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('resolves a ->toResource() on a local assigned from a model-returning static factory', function (): void {
    $target = readerFor()->read(readerMethod('localStaticFactoryToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('resolves a ->toResource() on a local narrowed by assert(instanceof)', function (): void {
    $target = readerFor()->read(readerMethod('assertNarrowedLocalToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
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

// region Multiple returns reconciliation

it('infers when two returns resolve to the same collection resource', function (): void {
    $target = readerFor()->read(readerMethod('twoSameCollections'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue()
        ->and($target?->paginated)->toBeFalse();
});

it('infers when two returns resolve to the same single ::make() resource', function (): void {
    $target = readerFor()->read(readerMethod('twoSameMakes'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('degrades when returns resolve to divergent resource classes with one note', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('divergentResourceClass'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'divergentResourceClass'),
        ))->toHaveCount(1);
});

it('degrades when returns resolve to divergent cardinality', function (): void {
    expect(readerFor()->read(readerMethod('divergentCardinality')))->toBeNull();
});

it('degrades when returns resolve to divergent pagination', function (): void {
    expect(readerFor()->read(readerMethod('divergentPagination')))->toBeNull();
});

it('degrades when one branch is a dynamic, non-whitelisted return', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('oneDynamicBranch'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'oneDynamicBranch'),
        ))->toHaveCount(1);
});

it('unwraps a returned variable per branch before reconciling', function (): void {
    $target = readerFor()->read(readerMethod('variableUnwrapBranches'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('ignores an explicit return null; branch alongside a resource branch', function (): void {
    $target = readerFor()->read(readerMethod('nullLiteralThenResource'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('ignores an early bare return; branch alongside a resource branch', function (): void {
    $target = readerFor()->read(readerMethod('earlyBareReturnThenResource'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('degrades when every branch is a bare or null sentinel return', function (): void {
    expect(readerFor()->read(readerMethod('allBareOrNull')))->toBeNull();
});

// endregion
