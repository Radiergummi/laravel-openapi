<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Collection;
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
 * A second, narrower Model for the ambiguous-assert fixture: two asserts on one local need two
 * classes that do not contradict each other, or PHPStan rejects the fixture before the reader
 * ever sees it.
 */
class ReaderFixtureSubAuthor extends Author {}

/**
 * A relation/accessor typed only via a class-level `@property` tag (no native typed property),
 * the conventional Eloquent shape.
 *
 * @property      string                  $label
 * @property      Author                  $primaryAuthor
 * @property-read Collection<int, Author> $contributors
 */
class ReaderFixtureDocument extends Model
{
    protected $guarded = [];
}

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

    public function docblockRelationPropertyToResource(ReaderFixtureDocument $document): JsonResource
    {
        return $document->primaryAuthor->toResource();
    }

    public function docblockRelationCollectionToResourceCollection(
        ReaderFixtureDocument $document,
    ): ResourceCollection {
        return $document->contributors->toResourceCollection();
    }

    public function methodReturnCollectionToResourceCollection(): ResourceCollection
    {
        return $this->authors()->toResourceCollection();
    }

    /**
     * @return Collection<int, Author>
     */
    public function authors(): Collection
    {
        return $this->author->newCollection();
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

    // region Returns past the first ten statements

    public function fourteenStatementsThenToResource(Author $author): JsonResource
    {
        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;
        $step12 = 12;
        $step13 = 13;
        $step14 = 14;

        return $author->toResource();
    }

    public function nullGuardClauseThenToResource(Author $author, bool $denied): ?JsonResource
    {
        if ($denied) {
            return null;
        }

        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;
        $step12 = 12;

        return $author->toResource();
    }

    public function jsonGuardClauseThenToResource(Author $author, bool $denied): mixed
    {
        if ($denied) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;
        $step12 = 12;

        return $author->toResource();
    }

    /**
     * @return AnonymousResourceCollection<NestedAuthorResource>
     */
    public function docblockPaginatedPastTheOldWindow(): AnonymousResourceCollection
    {
        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;
        $step12 = 12;

        return NestedAuthorResource::collection(Author::query()->paginate());
    }

    public function passthroughPastTheOldWindow(Author $author): JsonResource
    {
        return $this->reloadLate($author)->toResource();
    }

    public function reloadLate(Author $author): Model
    {
        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;
        $step12 = 12;

        return $author;
    }

    public function lateAssignedReturnedVariable(): AnonymousResourceCollection
    {
        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;
        $step12 = 12;

        $resources = NestedAuthorResource::collection(Author::all());

        return $resources;
    }

    public function truncatedThirdReturn(bool $flag, bool $other): JsonResource
    {
        if ($flag) {
            return NestedAuthorResource::make(Author::query()->firstOrFail());
        }

        if ($other) {
            return NestedAuthorResource::make(Author::query()->firstOrFail());
        }

        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;
        $step12 = 12;

        return LiteralOnlyResource::make(Author::query()->firstOrFail());
    }

    public function reassignedPastTheOldWindow(): AnonymousResourceCollection
    {
        $resources = NestedAuthorResource::collection(Author::all());

        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;

        $resources = NestedAuthorResource::collection(Author::query()->paginate());

        return $resources;
    }

    public function mutatedPastTheOldWindow(): AnonymousResourceCollection
    {
        /** @var AnonymousResourceCollection $resources */
        $resources = NestedAuthorResource::collection(Author::all());

        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;

        $resources['extra'] = NestedAuthorResource::make(Author::query()->firstOrFail());

        return $resources;
    }

    public function beyondTheBackstop(Author $author): JsonResource
    {
        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        $step4 = 4;
        $step5 = 5;
        $step6 = 6;
        $step7 = 7;
        $step8 = 8;
        $step9 = 9;
        $step10 = 10;
        $step11 = 11;
        $step12 = 12;
        $step13 = 13;
        $step14 = 14;
        $step15 = 15;
        $step16 = 16;
        $step17 = 17;
        $step18 = 18;
        $step19 = 19;
        $step20 = 20;
        $step21 = 21;
        $step22 = 22;
        $step23 = 23;
        $step24 = 24;
        $step25 = 25;
        $step26 = 26;
        $step27 = 27;
        $step28 = 28;
        $step29 = 29;
        $step30 = 30;
        $step31 = 31;
        $step32 = 32;
        $step33 = 33;
        $step34 = 34;
        $step35 = 35;
        $step36 = 36;
        $step37 = 37;
        $step38 = 38;
        $step39 = 39;
        $step40 = 40;
        $step41 = 41;
        $step42 = 42;
        $step43 = 43;
        $step44 = 44;
        $step45 = 45;
        $step46 = 46;
        $step47 = 47;
        $step48 = 48;
        $step49 = 49;
        $step50 = 50;
        $step51 = 51;
        $step52 = 52;
        $step53 = 53;
        $step54 = 54;
        $step55 = 55;
        $step56 = 56;
        $step57 = 57;
        $step58 = 58;
        $step59 = 59;
        $step60 = 60;
        $step61 = 61;
        $step62 = 62;
        $step63 = 63;
        $step64 = 64;
        $step65 = 65;
        $step66 = 66;
        $step67 = 67;
        $step68 = 68;
        $step69 = 69;
        $step70 = 70;
        $step71 = 71;
        $step72 = 72;
        $step73 = 73;
        $step74 = 74;
        $step75 = 75;
        $step76 = 76;
        $step77 = 77;
        $step78 = 78;
        $step79 = 79;
        $step80 = 80;
        $step81 = 81;
        $step82 = 82;
        $step83 = 83;
        $step84 = 84;
        $step85 = 85;
        $step86 = 86;
        $step87 = 87;
        $step88 = 88;
        $step89 = 89;
        $step90 = 90;
        $step91 = 91;
        $step92 = 92;
        $step93 = 93;
        $step94 = 94;
        $step95 = 95;
        $step96 = 96;
        $step97 = 97;
        $step98 = 98;
        $step99 = 99;
        $step100 = 100;

        return $author->toResource();
    }

    // endregion

    // region assert() narrowing guards

    public function assertDominatesBranchReturn(bool $flag): JsonResource
    {
        $subject = $this->makeSomething();
        assert($subject instanceof Author);

        if ($flag) {
            return $subject->toResource();
        }

        return AuthorResource::make($subject);
    }

    public function assertAfterTheBranchReturn(bool $flag): JsonResource
    {
        $subject = $this->makeSomething();

        if ($flag) {
            return $subject->toResource();
        }

        assert($subject instanceof Author);

        return AuthorResource::make($subject);
    }

    public function rebindingInsideTheContainingBranchStatement(bool $flag): JsonResource
    {
        $subject = $this->makeSomething();
        $step1 = 1;
        $step2 = 2;
        $step3 = 3;
        assert($subject instanceof Author);

        if ($flag) {
            $subject = $this->makeSomething();

            return $subject->toResource();
        }

        return AuthorResource::make($subject);
    }

    /**
     * @param list<Author> $others
     */
    public function foreachRebindsAssertedLocal(array $others): JsonResource
    {
        $subject = $this->makeSomething();
        assert($subject instanceof Author);

        foreach ($others as $subject) {
        }

        return $subject->toResource();
    }

    public function destructuringRebindsAssertedLocal(): JsonResource
    {
        $subject = $this->makeSomething();
        assert($subject instanceof Author);
        [$subject, $other] = $this->modelPair();

        return $subject->toResource();
    }

    public function referenceRebindsAssertedLocal(): JsonResource
    {
        $other = $this->makeSomething();
        $subject = $this->makeSomething();
        assert($subject instanceof Author);
        $subject = &$other;

        return $subject->toResource();
    }

    public function reassignmentRebindsAssertedLocal(): JsonResource
    {
        $subject = $this->makeSomething();
        assert($subject instanceof Author);
        $subject = $this->makeSomething();

        return $subject->toResource();
    }

    public function rebindingBeforeTheAssertStillResolves(): JsonResource
    {
        $subject = $this->makeSomething();
        $subject = $this->makeSomething();
        assert($subject instanceof Author);

        return $subject->toResource();
    }

    public function rebindingAfterTheReturnStillResolves(bool $flag): JsonResource
    {
        $subject = $this->makeSomething();
        assert($subject instanceof Author);

        if ($flag) {
            return $subject->toResource();
        }

        $subject = $this->makeSomething();

        return AuthorResource::make($subject);
    }

    public function twoAssertsOnTheSameLocal(): JsonResource
    {
        $subject = $this->makeSomething();
        assert($subject instanceof Author);
        assert($subject instanceof ReaderFixtureSubAuthor);

        return $subject->toResource();
    }

    public function branchAssignedThenAssertedLocal(bool $flag): JsonResource
    {
        if ($flag) {
            $subject = $this->makeSomething();
        } else {
            $subject = $this->makeSomething();
        }

        assert($subject instanceof Author);

        return $subject->toResource();
    }

    public function unresolvableLocalWithoutAssert(): JsonResource
    {
        $subject = $this->makeSomething();

        return $subject->toResource();
    }

    /**
     * @return array{0: Author, 1: Author}
     */
    public function modelPair(): array
    {
        return [$this->author, $this->author];
    }

    // endregion
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

it('resolves a ->toResource() whose receiver is a @property-typed relation', function (): void {
    $target = readerFor()->read(readerMethod('docblockRelationPropertyToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('resolves ->toResourceCollection() from a @property Collection<Model> relation element', function (): void {
    $target = readerFor()->read(readerMethod('docblockRelationCollectionToResourceCollection'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('resolves ->toResourceCollection() from a method @return Collection<Model> generic', function (): void {
    $target = readerFor()->read(readerMethod('methodReturnCollectionToResourceCollection'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeTrue();
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

// region Returns past the first ten statements

it('resolves a trailing ->toResource() after fourteen leading statements', function (): void {
    $target = readerFor()->read(readerMethod('fourteenStatementsThenToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('resolves a trailing ->toResource() after a null-returning guard clause', function (): void {
    $target = readerFor()->read(readerMethod('nullGuardClauseThenToResource'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('refuses when a guard clause returns a non-resource response alongside the resource', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('jsonGuardClauseThenToResource'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains(
                $record['message'],
                'jsonGuardClauseThenToResource has a return path that does not resolve to a resource type',
            ),
        ))->toHaveCount(1);
});

it('reads pagination evidence from a docblock-typed body past the first ten statements', function (): void {
    $target = readerFor()->read(readerMethod('docblockPaginatedPastTheOldWindow'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue()
        ->and($target?->paginated)->toBeTrue();
});

it('resolves a passthrough callee whose return sits past the first ten statements', function (): void {
    $target = readerFor()->read(readerMethod('passthroughPastTheOldWindow'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('resolves a returned variable assigned past the first ten statements', function (): void {
    $target = readerFor()->read(readerMethod('lateAssignedReturnedVariable'));

    expect($target?->resourceClass)->toBe(NestedAuthorResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('refuses a third, divergent return that the old window truncated away', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('truncatedThirdReturn'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains(
                $record['message'],
                'truncatedThirdReturn has multiple returns resolving to different resource types',
            ),
        ))->toHaveCount(1);
});

it('refuses a returned variable reassigned past the first ten statements', function (): void {
    expect(readerFor()->read(readerMethod('reassignedPastTheOldWindow')))->toBeNull();
});

it('refuses a returned variable mutated past the first ten statements', function (): void {
    expect(readerFor()->read(readerMethod('mutatedPastTheOldWindow')))->toBeNull();
});

it('refuses a return sitting beyond the pathological-input backstop', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('beyondTheBackstop'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains(
                $record['message'],
                'beyondTheBackstop has no unconditional top-level return in the scanned statements',
            ),
        ))->toHaveCount(1);
});

// endregion

// region assert() narrowing guards

it('lets a top-level assert dominate a return inside a later branch', function (): void {
    $target = readerFor()->read(readerMethod('assertDominatesBranchReturn'));

    expect($target?->resourceClass)->toBe(AuthorResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('refuses a branch return that no assert precedes, even when a later branch has one', function (): void {
    expect(readerFor()->read(readerMethod('assertAfterTheBranchReturn')))->toBeNull();
});

it('refuses when the rebinding sits inside the very statement containing the return', function (): void {
    expect(readerFor()->read(readerMethod('rebindingInsideTheContainingBranchStatement')))->toBeNull();
});

it('refuses an asserted local rebound before its return', function (string $fixture): void {
    expect(readerFor()->read(readerMethod($fixture)))->toBeNull();
})->with([
    'foreachRebindsAssertedLocal',
    'destructuringRebindsAssertedLocal',
    'referenceRebindsAssertedLocal',
    'reassignmentRebindsAssertedLocal',
]);

it('still resolves when the rebinding precedes the assert', function (): void {
    $target = readerFor()->read(readerMethod('rebindingBeforeTheAssertStillResolves'));

    expect($target?->resourceClass)->toBe(AuthorResource::class);
});

it('still resolves when the rebinding follows the return it would void', function (): void {
    $target = readerFor()->read(readerMethod('rebindingAfterTheReturnStillResolves'));

    expect($target?->resourceClass)->toBe(AuthorResource::class);
});

it('refuses two asserts on the same local without a note of its own', function (): void {
    $logger = recordingLogger();
    $target = readerFor($logger)->read(readerMethod('twoAssertsOnTheSameLocal'));

    expect($target)->toBeNull()
        ->and(array_filter(
            $logger->records,
            static fn(array $record): bool => str_contains($record['message'], 'twoAssertsOnTheSameLocal'),
        ))->toHaveCount(1);
});

it('still resolves a branch-assigned local narrowed by a single dominating assert', function (): void {
    $target = readerFor()->read(readerMethod('branchAssignedThenAssertedLocal'));

    expect($target?->resourceClass)->toBe(AuthorResource::class);
});

it('does not carry one method\'s asserted narrowing into the next', function (): void {
    $reader = readerFor();

    expect($reader->read(readerMethod('assertNarrowedLocalToResource'))?->resourceClass)
        ->toBe(AuthorResource::class)
        ->and($reader->read(readerMethod('unresolvableLocalWithoutAssert')))->toBeNull();
});

// endregion
