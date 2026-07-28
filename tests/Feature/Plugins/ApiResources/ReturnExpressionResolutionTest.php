<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceResponseAmbiguous;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\LiteralOnlyResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\NestedAuthorResource;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

use function array_any;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function intval;
use function iterator_to_array;
use function random_int;
use function str_contains;

uses()->group('openapi', 'plugin:api-resources');

/**
 * Fixture controller for the #108 return-expression resolution: every action types its return
 * as a base resource class, so the concrete resource is only recoverable from the method body
 * (or the @return docblock generic). Actions are never invoked — only parsed.
 */
class ReturnExpressionController extends Controller
{
    /** An app-defined status constant, referenced as `self::ACCEPTED` by one of the actions. */
    public const int ACCEPTED = 202;

    public function paginatedCollection(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::query()->paginate());
    }

    public function unpaginatedCollection(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::all());
    }

    public function collectCollection(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collect(Author::query()->paginate());
    }

    public function collectUnpaginated(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collect(Author::all());
    }

    public function staticMake(): JsonResource
    {
        return NestedAuthorResource::make(Author::query()->firstOrFail());
    }

    public function newSingle(): JsonResource
    {
        return new NestedAuthorResource(Author::query()->firstOrFail());
    }

    public function assignedThenReturned(): AnonymousResourceCollection
    {
        $authors = NestedAuthorResource::collection(Author::query()->paginate());

        return $authors;
    }

    /**
     * @return AnonymousResourceCollection<LiteralOnlyResource>
     */
    public function docblockGeneric(): AnonymousResourceCollection
    {
        return $this->authors();
    }

    /**
     * @return AnonymousResourceCollection<LiteralOnlyResource>
     */
    public function docblockDisagreesWithBody(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::all());
    }

    /**
     * @return AnonymousResourceCollection<LiteralOnlyResource>
     */
    public function docblockCollectionNoPaginate(): AnonymousResourceCollection
    {
        return LiteralOnlyResource::collection(Author::all());
    }

    /**
     * @return AnonymousResourceCollection<LiteralOnlyResource>
     */
    public function docblockCollectionPaginated(): AnonymousResourceCollection
    {
        return LiteralOnlyResource::collection(Author::query()->paginate());
    }

    public function toResourceConvention(Author $author): JsonResource
    {
        return $author->toResource();
    }

    public function guardClausesThenToResource(Author $author, bool $denied): ?JsonResource
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

    public function toResourceExplicit(Author $author): JsonResource
    {
        return $author->toResource(NestedAuthorResource::class);
    }

    public function toResourceCollectionExplicit(): ResourceCollection
    {
        return Author::query()->paginate()->toResourceCollection(NestedAuthorResource::class);
    }

    /**
     * @return ResourceCollection<LiteralOnlyResource>
     */
    public function docblockCollectionToResourceCollectionPaginated(): ResourceCollection
    {
        return Author::query()->paginate()->toResourceCollection(LiteralOnlyResource::class);
    }

    /**
     * @return AnonymousResourceCollection<LiteralOnlyResource>
     */
    public function docblockCollectionConditionalBody(bool $flag): AnonymousResourceCollection
    {
        if ($flag) {
            $collection = LiteralOnlyResource::collection(Author::query()->paginate());
        } else {
            $collection = LiteralOnlyResource::collection(Author::all());
        }

        return $collection;
    }

    public function wrappedModel(Article $article): JsonResource
    {
        return new JsonResource($article);
    }

    public function chainedAdditional(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::query()->paginate())
            ->additional(['meta' => ['generated' => true]]);
    }

    public function sameTypeMultipleReturns(bool $flag): AnonymousResourceCollection
    {
        if ($flag) {
            return NestedAuthorResource::collection(Author::query()->paginate());
        }

        return NestedAuthorResource::collection(Author::query()->paginate());
    }

    public function divergentMultipleReturns(bool $flag): AnonymousResourceCollection
    {
        if ($flag) {
            return NestedAuthorResource::collection(Author::all());
        }

        return LiteralOnlyResource::collection(Author::all());
    }

    public function refusedVariable(): AnonymousResourceCollection
    {
        return $this->authors();
    }

    public function refusedConditional(): JsonResource
    {
        return random_int(0, 1) === 1
            ? NestedAuthorResource::make(Author::query()->firstOrFail())
            : new LiteralOnlyResource(Author::query()->firstOrFail());
    }

    public function refusedReassignedVariable(): AnonymousResourceCollection
    {
        $authors = NestedAuthorResource::collection(Author::all());

        if (random_int(0, 1) === 1) {
            $authors = LiteralOnlyResource::collection(Author::all());
        }

        return $authors;
    }

    public function baseClassCollection(): AnonymousResourceCollection
    {
        return JsonResource::collection(Author::all());
    }

    #[ResponseResource(LiteralOnlyResource::class, collection: true)]
    public function attributeWins(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::all());
    }

    public function jsonWrappedSingle(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 201);
    }

    public function jsonWrappedCollection(): JsonResponse
    {
        return response()->json(NestedAuthorResource::collection(Author::query()->paginate()), 201);
    }

    public function jsonWrappedNonResource(): JsonResponse
    {
        $payload = ['ok' => true];

        return response()->json($payload, 201);
    }

    public function jsonWrappedNoStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()));
    }

    public function jsonWrappedNamedStatus(): JsonResponse
    {
        return response()->json(
            data: NestedAuthorResource::make(Author::query()->firstOrFail()),
            status: 202,
        );
    }

    public function jsonWrappedConstantStatus(): JsonResponse
    {
        return response()->json(
            NestedAuthorResource::make(Author::query()->firstOrFail()),
            Response::HTTP_ACCEPTED,
        );
    }

    public function jsonWrappedSelfConstantStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), self::ACCEPTED);
    }

    public function jsonWrappedNonLiteralStatus(): JsonResponse
    {
        $status = random_int(200, 202);

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), $status);
    }

    public function jsonWrappedForbiddenStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
    }

    public function jsonWrappedNoContentStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 204);
    }

    public function jsonWrappedUnprocessableStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 422);
    }

    public function jsonWrappedConstantForbiddenStatus(): JsonResponse
    {
        return response()->json(
            NestedAuthorResource::make(Author::query()->firstOrFail()),
            Response::HTTP_FORBIDDEN,
        );
    }

    public function jsonWrappedResetContentStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 205);
    }

    public function jsonWrappedFieldlessForbiddenStatus(): JsonResponse
    {
        return response()->json(FieldlessForbiddenResource::make(Author::query()->firstOrFail()), 403);
    }

    public function jsonWrappedMixedErrorAndBare(bool $flag): JsonResponse|JsonResource
    {
        if ($flag) {
            return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
        }

        return NestedAuthorResource::make(Author::query()->firstOrFail());
    }

    public function jsonWrappedAllForbidden(bool $flag): JsonResponse
    {
        if ($flag) {
            return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
        }

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
    }

    public function jsonWrappedDivergentStatuses(bool $flag): JsonResponse
    {
        if ($flag) {
            return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 201);
        }

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 202);
    }

    public function jsonWrappedBareAndAuthoredStatuses(bool $flag): JsonResponse
    {
        if ($flag) {
            return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 202);
        }

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()));
    }

    public function jsonWrappedAdditionalChain(): JsonResponse
    {
        return response()->json(
            NestedAuthorResource::collection(Author::query()->paginate())
                ->additional(['meta' => ['generated' => true]]),
            201,
        );
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedForbiddenStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedAcceptedStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 202);
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedOpaqueForbiddenStatus(): JsonResponse
    {
        return response()->json($this->presenterOutput(), 403);
    }

    #[ResponseResource(NestedAuthorResource::class, collection: true)]
    public function attributedCollectionForbiddenStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::collection(Author::all()), 403);
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedDynamicStatus(): JsonResponse
    {
        $status = random_int(200, 202);

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), $status);
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedMixedErrorAndBare(bool $flag): JsonResponse|JsonResource
    {
        if ($flag) {
            return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
        }

        return NestedAuthorResource::make(Author::query()->firstOrFail());
    }

    /**
     * A sentinel `return null;` beside an authored status. Unlike a bare *resource* return, which
     * authors no status and disagrees, this branch is skipped outright, so the lone `403` stands.
     */
    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedSentinelAndForbidden(bool $flag): ?JsonResponse
    {
        if ($flag) {
            return null;
        }

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
    }

    /**
     * The mirror of {@see attributedMixedErrorAndBare()}: the *success* status comes first, so the
     * disagreement is only visible to a reconciliation that compares every branch rather than
     * keeping whichever status it read last.
     */
    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedSuccessThenError(bool $flag): JsonResponse
    {
        if ($flag) {
            return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 202);
        }

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedAllForbidden(bool $flag): JsonResponse
    {
        if ($flag) {
            return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
        }

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedHeaderChain(): JsonResponse
    {
        return response()
            ->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403)
            ->header('X-Author', 'yes');
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedNoContentStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 204);
    }

    #[ResponseResource(NestedAuthorResource::class)]
    public function attributedResetContentStatus(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 205);
    }

    #[ResponseResource(FieldlessForbiddenResource::class)]
    public function attributedFieldlessForbiddenStatus(): JsonResponse
    {
        return response()->json(FieldlessForbiddenResource::make(Author::query()->firstOrFail()), 403);
    }

    private function authors(): AnonymousResourceCollection
    {
        throw new LogicException('Fixture helper; never invoked.');
    }

    /**
     * A value the return-expression reader cannot resolve to a resource, so the wrapper's status is
     * the only thing readable about the return.
     *
     * @return array<string, mixed>
     */
    private function presenterOutput(): array
    {
        throw new LogicException('Fixture helper; never invoked.');
    }
}

/**
 * A resource with neither `#[ResourceField]` nor a readable `toArray()`: documenting it emits a
 * `resource.fields-undeclared` finding and registers a component, so it detects both side effects
 * a refused response must not leave behind.
 */
class FieldlessForbiddenResource extends JsonResource {}

/**
 * Fixture controller whose `store()` authors its own status, so the authored value and the
 * resourceful-route convention's 201 disagree.
 */
class AuthoredStatusStoreController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 202);
    }
}

/**
 * Fixture controller whose `store()` authors no status, so the resourceful-route convention's 201
 * must still apply.
 */
class ConventionalStatusStoreController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()));
    }
}

/**
 * Fixture controller whose `store()` authors conflicting statuses across its two returns, so the
 * status claim is dropped and the resourceful-route convention has to fill the gap.
 */
class DivergentStatusStoreController extends Controller
{
    public function store(bool $flag): JsonResponse
    {
        if ($flag) {
            return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 202);
        }

        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 200);
    }
}

/**
 * Fixture controller whose `store()` wraps its resource in a non-2xx status, so the resource path
 * yields and only the resourceful-route convention is left to speak.
 */
class ForbiddenStatusStoreController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 422);
    }
}

/**
 * Fixture controller carrying a **class-level** `#[ResponseResource]`, whose `store()` wraps the
 * resource in a non-2xx status: class-level placement must not shield the wrapper from the read.
 */
#[ResponseResource(NestedAuthorResource::class)]
class ClassAttributedForbiddenStoreController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 403);
    }
}

/**
 * Fixture controller whose attributed `store()` authors a `200`, which the resourceful-route
 * convention would otherwise renumber to `201`.
 */
class AttributedOkStoreController extends Controller
{
    #[ResponseResource(NestedAuthorResource::class)]
    public function store(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 200);
    }
}

/**
 * Fixture controller whose attributed `store()` authors a `202`, so the authored status has to beat
 * the convention's `201` on the attribute path too.
 */
class AttributedAcceptedStoreController extends Controller
{
    #[ResponseResource(NestedAuthorResource::class)]
    public function store(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 202);
    }
}

/**
 * Fixture controller whose attributed `destroy()` authors a `204`: the resource path yields, and the
 * resourceful-route convention then names `204` anyway, restoring the authored status.
 */
class AttributedNoContentDestroyController extends Controller
{
    #[ResponseResource(NestedAuthorResource::class)]
    public function destroy(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 204);
    }
}

/**
 * Fixture controller whose attributed `destroy()` authors a `205`: the convention that fills the gap
 * names `204`, so the documented status is neither the authored one nor a body-bearing success.
 */
class AttributedResetContentDestroyController extends Controller
{
    #[ResponseResource(NestedAuthorResource::class)]
    public function destroy(): JsonResponse
    {
        return response()->json(NestedAuthorResource::make(Author::query()->firstOrFail()), 205);
    }
}

/**
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function successSchema(array $spec, string $path, string $status = '200', string $verb = 'get'): array
{
    $schema = $spec['paths'][$path][$verb]['responses'][$status]['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull();

    return $schema;
}

/**
 * The 2xx statuses an operation documents, so a test can pin that exactly one success response
 * exists rather than only that the expected one is present.
 *
 * @param array<int|string, mixed> $responses
 *
 * @return list<int>
 */
function successStatuses(array $responses): array
{
    $statuses = array_map(intval(...), array_keys($responses));

    return array_values(array_filter(
        $statuses,
        static fn(int $status): bool => $status >= 200 && $status < 300,
    ));
}

// region Collection shapes

it('resolves X::collection() behind an AnonymousResourceCollection signature', function (): void {
    Route::get('/authors', [ReturnExpressionController::class, 'paginatedCollection']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/authors');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        // Hand-off to the toArray() reader: the resolved resource's fields are inferred.
        ->and($spec['components']['schemas']['NestedAuthorResource']['properties'])->toHaveKeys(['id', 'name']);
});

it('documents a plain {data} envelope when the collection argument is not visibly paginated', function (): void {
    Route::get('/authors-unpaginated', [ReturnExpressionController::class, 'unpaginatedCollection']);

    $schema = successSchema(generateSpec(), '/authors-unpaginated');

    expect($schema['properties'])->toHaveKey('data')
        ->and($schema['properties'])->not->toHaveKeys(['links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('keeps the paginated envelope for the two-statement assign-then-return form', function (): void {
    Route::get('/authors-assigned', [ReturnExpressionController::class, 'assignedThenReturned']);

    $schema = successSchema(generateSpec(), '/authors-assigned');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('resolves X::collect() behind an AnonymousResourceCollection signature', function (): void {
    Route::get('/authors-collect', [ReturnExpressionController::class, 'collectCollection']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/authors-collect');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and($spec['components']['schemas']['NestedAuthorResource']['properties'])->toHaveKeys(['id', 'name']);
});

it('documents a plain {data} envelope for an unpaginated X::collect() argument', function (): void {
    Route::get('/authors-collect-unpaginated', [ReturnExpressionController::class, 'collectUnpaginated']);

    $schema = successSchema(generateSpec(), '/authors-collect-unpaginated');

    expect($schema['properties'])->toHaveKey('data')
        ->and($schema['properties'])->not->toHaveKeys(['links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('resolves the resource through a whitelisted ->additional() chain', function (): void {
    Route::get('/authors-chained', [ReturnExpressionController::class, 'chainedAdditional']);

    $schema = successSchema(generateSpec(), '/authors-chained');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('resolves the resource when all returns are the same collection type', function (): void {
    Route::get('/authors-multi', [ReturnExpressionController::class, 'sameTypeMultipleReturns']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/authors-multi');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('degrades when the multiple returns resolve to divergent resources', function (): void {
    Route::get('/authors-divergent', [ReturnExpressionController::class, 'divergentMultipleReturns']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $response = $spec['paths']['/authors-divergent']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'divergentMultipleReturns')
            && str_contains($record['message'], 'ResponseResource'),
    );

    expect($noted)->toBeTrue();
});

// endregion

// region Single shapes

it('resolves X::make() behind a base JsonResource signature', function (): void {
    Route::get('/author-make', [ReturnExpressionController::class, 'staticMake']);

    $schema = successSchema(generateSpec(), '/author-make');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('resolves new X() behind a base JsonResource signature', function (): void {
    Route::get('/author-new', [ReturnExpressionController::class, 'newSingle']);

    $schema = successSchema(generateSpec(), '/author-new');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

// endregion

// region toResource() & wrapped models

it('resolves a bare $model->toResource() through the conventional resource class', function (): void {
    Route::get('/authors/{author}', [ReturnExpressionController::class, 'toResourceConvention']);

    $schema = successSchema(generateSpec(), '/authors/{author}');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/AuthorResource');
});

it('resolves a trailing ->toResource() behind a run of guard clauses', function (): void {
    Route::get('/guarded-authors/{author}', [ReturnExpressionController::class, 'guardClausesThenToResource']);

    $schema = successSchema(generateSpec(), '/guarded-authors/{author}');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/AuthorResource');
});

it('resolves $model->toResource(X::class) to the named resource', function (): void {
    Route::get('/authors-explicit/{author}', [ReturnExpressionController::class, 'toResourceExplicit']);

    $schema = successSchema(generateSpec(), '/authors-explicit/{author}');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('resolves ->toResourceCollection(X::class) to a paginated collection', function (): void {
    Route::get('/authors-to-collection', [ReturnExpressionController::class, 'toResourceCollectionExplicit']);

    $schema = successSchema(generateSpec(), '/authors-to-collection');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('documents new JsonResource($typedModel) as the wrapped model schema', function (): void {
    Route::get('/articles/{article}', [ReturnExpressionController::class, 'wrappedModel']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/articles/{article}');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/Article')
        ->and($spec['components']['schemas'])->toHaveKey('Article');
});

// endregion

// region @return docblock generic

it('honours a @return AnonymousResourceCollection<X> generic', function (): void {
    Route::get('/literal-collection', [ReturnExpressionController::class, 'docblockGeneric']);

    $schema = successSchema(generateSpec(), '/literal-collection');

    expect($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/LiteralOnlyResource');
});

it('lets the @return generic win over a disagreeing body expression', function (): void {
    Route::get('/literal-vs-body', [ReturnExpressionController::class, 'docblockDisagreesWithBody']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/literal-vs-body');

    expect($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/LiteralOnlyResource')
        ->and($spec['components']['schemas'])->not->toHaveKey('NestedAuthorResource');
});

it('emits a plain {data} envelope for @return Collection<X> when the body uses ::collection() without paginate()', function (): void {
    Route::get('/literal-no-paginate', [ReturnExpressionController::class, 'docblockCollectionNoPaginate']);

    $schema = successSchema(generateSpec(), '/literal-no-paginate');

    expect($schema['properties'])->toHaveKey('data')
        ->and($schema['properties'])->not->toHaveKeys(['links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/LiteralOnlyResource');
});

it('emits a paginated {data,links,meta} envelope for @return Collection<X> when the body ends in paginate()', function (): void {
    Route::get('/literal-paginated', [ReturnExpressionController::class, 'docblockCollectionPaginated']);

    $schema = successSchema(generateSpec(), '/literal-paginated');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/LiteralOnlyResource');
});

it('emits a paginated {data,links,meta} envelope for @return Collection<X> when the body uses ->toResourceCollection() on a paginating receiver', function (): void {
    Route::get('/literal-to-resource-collection-paginated', [ReturnExpressionController::class, 'docblockCollectionToResourceCollectionPaginated']);

    $schema = successSchema(generateSpec(), '/literal-to-resource-collection-paginated');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/LiteralOnlyResource');
});

it('defaults to a plain {data} envelope for @return Collection<X> when the body is not a recognisable collection shape', function (): void {
    Route::get('/literal-no-body', [ReturnExpressionController::class, 'docblockGeneric']);

    $schema = successSchema(generateSpec(), '/literal-no-body');

    expect($schema['properties'])->toHaveKey('data')
        ->and($schema['properties'])->not->toHaveKeys(['links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/LiteralOnlyResource');
});

it('emits no spurious refusal notice when the @return generic resolves the resource but the body is conditionally assigned', function (): void {
    Route::get('/literal-conditional-body', [ReturnExpressionController::class, 'docblockCollectionConditionalBody']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $schema = successSchema(generateSpec(), '/literal-conditional-body');

    // The resource resolves from the docblock — the schema must be emitted.
    expect($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/LiteralOnlyResource');

    // No refusal notice should fire because the docblock already supplies the resource.
    $spurious = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'docblockCollectionConditionalBody')
            && str_contains($record['message'], 'ResponseResource'),
    );

    expect($spurious)->toBeFalse();
});

// endregion

// region Refusals & degradation

it('degrades an unresolvable collection return to a bare 200 with a note', function (): void {
    Route::get('/authors-refused', [ReturnExpressionController::class, 'refusedVariable']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $response = $spec['paths']['/authors-refused']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'refusedVariable')
            && str_contains($record['message'], 'ResponseResource'),
    );

    expect($noted)->toBeTrue();
});

it('refuses a conditional return expression with a note', function (): void {
    Route::get('/author-conditional', [ReturnExpressionController::class, 'refusedConditional']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();

    expect($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'refusedConditional'),
    );

    expect($noted)->toBeTrue();
});

it('refuses a conditionally reassigned variable with a note', function (): void {
    Route::get('/authors-reassigned', [ReturnExpressionController::class, 'refusedReassignedVariable']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $response = $spec['paths']['/authors-reassigned']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'refusedReassignedVariable'),
    );

    expect($noted)->toBeTrue();
});

// endregion

// region Precedence & lint interplay

it('lets #[ResponseResource] win over the body expression', function (): void {
    Route::get('/authors-attributed', [ReturnExpressionController::class, 'attributeWins']);

    $schema = successSchema(generateSpec(), '/authors-attributed');

    expect($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/LiteralOnlyResource');
});

it('stops flagging resource.response-ambiguous for a body-resolved collection', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ReturnExpressionController::class,
        'paginatedCollection',
        '/authors',
    );

    $rule = new ResourceResponseAmbiguous(ResourceClassLocator::create());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});

it('still flags resource.response-ambiguous for a base JsonResource::collection() body', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ReturnExpressionController::class,
        'baseClassCollection',
        '/authors-base-class',
    );

    $rule = new ResourceResponseAmbiguous(ResourceClassLocator::create());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.response-ambiguous');
});

it('still flags resource.response-ambiguous for an unresolvable collection', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ReturnExpressionController::class,
        'refusedVariable',
        '/authors-refused',
    );

    $rule = new ResourceResponseAmbiguous(ResourceClassLocator::create());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.response-ambiguous');
});

// endregion

// region response()->json(<resource>) unwrapping

it('documents a resource wrapped in response()->json(X::make(...), 201) under the authored 201', function (): void {
    Route::get('/json-single', [ReturnExpressionController::class, 'jsonWrappedSingle']);

    $spec = generateSpec();
    $responses = $spec['paths']['/json-single']['get']['responses'];
    $schema = successSchema($spec, '/json-single', '201');

    // The resource $ref wins over the empty inline-JSON shape; only one 2xx is emitted.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and($spec['components']['schemas']['NestedAuthorResource']['properties'])->toHaveKeys(['id', 'name'])
        ->and(successStatuses($responses))->toBe([201]);
});

it('documents a collection wrapped in response()->json(X::collection(...), 201) under the authored 201', function (): void {
    Route::get('/json-collection', [ReturnExpressionController::class, 'jsonWrappedCollection']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-collection', '201');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-collection']['get']['responses']))->toBe([201]);
});

it('keeps the conventional 200 when the response()->json() wrapper authors no status', function (): void {
    Route::get('/json-no-status', [ReturnExpressionController::class, 'jsonWrappedNoStatus']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-no-status');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-no-status']['get']['responses']))->toBe([200]);
});

it('reads a named status: argument on the response()->json() wrapper', function (): void {
    Route::get('/json-named-status', [ReturnExpressionController::class, 'jsonWrappedNamedStatus']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-named-status', '202');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-named-status']['get']['responses']))->toBe([202]);
});

it('resolves a class-constant status on the response()->json() wrapper', function (): void {
    Route::get('/json-constant-status', [ReturnExpressionController::class, 'jsonWrappedConstantStatus']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-constant-status', '202');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-constant-status']['get']['responses']))->toBe([202]);
});

it('degrades to the conventional 200 when the wrapper status is not statically readable', function (): void {
    Route::get('/json-dynamic-status', [ReturnExpressionController::class, 'jsonWrappedNonLiteralStatus']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-dynamic-status');

    // The unreadable status must not cost the resource its schema.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-dynamic-status']['get']['responses']))->toBe([200]);
});

it('pins the self:: limitation shared with the literal-body reader: the status stays unread', function (): void {
    Route::get('/json-self-constant-status', [ReturnExpressionController::class, 'jsonWrappedSelfConstantStatus']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-self-constant-status');

    // `self::` is deliberately not resolved here, matching the literal-body reader; lifting the
    // limitation belongs in one change across both, not as a divergence.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-self-constant-status']['get']['responses']))->toBe([200]);
});

it('falls back to the conventional 200 when several returns author different statuses', function (): void {
    Route::get('/json-divergent-statuses', [ReturnExpressionController::class, 'jsonWrappedDivergentStatuses']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-divergent-statuses');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-divergent-statuses']['get']['responses']))->toBe([200]);
});

it('drops the status claim when one return authors a status and another leaves it bare', function (): void {
    Route::get('/json-bare-and-authored', [ReturnExpressionController::class, 'jsonWrappedBareAndAuthoredStatuses']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-bare-and-authored');

    // A bare return against an authored one is its own disagreement, not two ints differing. The
    // authored branch comes first on purpose: the null must be the side being compared.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-bare-and-authored']['get']['responses']))->toBe([200]);
});

it('lets the resourceful-route convention fill the gap when the returns disagree on the status', function (): void {
    Route::post('/divergent-widgets', [DivergentStatusStoreController::class, 'store']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/divergent-widgets', '201', 'post');

    // Dropping the claim leaves statusIsExplicit false, so the convention supplies 201 and the
    // resource survives: the operation degrades its status claim without losing information.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/divergent-widgets']['post']['responses']))->toBe([201]);
});

it('carries the authored status through a whitelisted ->additional() chain', function (): void {
    Route::get('/json-additional-chain', [ReturnExpressionController::class, 'jsonWrappedAdditionalChain']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-additional-chain', '201');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-additional-chain']['get']['responses']))->toBe([201]);
});

it('yields the resource entirely when the wrapper authors a non-2xx status', function (): void {
    Route::get('/json-forbidden-status', [ReturnExpressionController::class, 'jsonWrappedForbiddenStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/json-forbidden-status']['get']['responses'];

    // A 403 cannot carry a success envelope, so the resource path says nothing at all: the 200 is
    // the body-less default every non-resource action gets, and no component is registered for a
    // resource the document never references.
    expect($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('yields for a 422 wrapper too, so the rule is not pinned to one status', function (): void {
    Route::get('/json-unprocessable-status', [ReturnExpressionController::class, 'jsonWrappedUnprocessableStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/json-unprocessable-status']['get']['responses'];

    expect($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('422')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('yields for a non-2xx class constant, not only a bare integer literal', function (): void {
    Route::get('/json-constant-forbidden', [ReturnExpressionController::class, 'jsonWrappedConstantForbiddenStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/json-constant-forbidden']['get']['responses'];

    // Dropping the range filter widened the read for every status shape, so the constant path
    // (covered at 2xx by the 202 case) needs its own non-2xx counterpart.
    expect($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('yields for a 205, which may no more carry a body than a 204', function (): void {
    Route::get('/json-reset-content', [ReturnExpressionController::class, 'jsonWrappedResetContentStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/json-reset-content']['get']['responses'];

    // Unlike a 204, nothing downstream claims the call: Core's inline-JSON resolver yields for a
    // resource argument, so the operation keeps only its body-less default. Losing the status is
    // the lesser error, since a 205 carrying a resource envelope is invalid either way.
    expect(successStatuses($responses))->toBe([200])
        ->and($responses['200'])->not->toHaveKey('content')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('documents the resource at the conventional 200 when only one branch authors the error status', function (): void {
    Route::get('/json-mixed-error-and-bare', [ReturnExpressionController::class, 'jsonWrappedMixedErrorAndBare']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/json-mixed-error-and-bare');

    // The branches disagree on the status, so the claim is dropped rather than the resource, and
    // the legitimate success branch keeps its schema. Refusing in the reader would lose it.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/json-mixed-error-and-bare']['get']['responses']))->toBe([200]);
});

it('yields when every branch agrees on the same non-2xx status', function (): void {
    Route::get('/json-all-forbidden', [ReturnExpressionController::class, 'jsonWrappedAllForbidden']);

    $spec = generateSpec();
    $responses = $spec['paths']['/json-all-forbidden']['get']['responses'];

    // Reconciliation carries the agreed 403 through to the resolver instead of nulling it, which
    // is what lets the refusal fire on a multi-branch action.
    expect($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('does not resurrect the resourceful 201 for a store() wrapped in a non-2xx status', function (): void {
    Route::post('/forbidden-widgets', [ForbiddenStatusStoreController::class, 'store']);

    $spec = generateSpec();
    $responses = $spec['paths']['/forbidden-widgets']['post']['responses'];

    // With no primary response at all the convention still names the resourceful 201, but it has
    // no body to carry: the resource must not come back through that door.
    expect(successStatuses($responses))->toBe([201])
        ->and($responses['201'])->not->toHaveKey('content')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('leaves neither a lint finding nor a component behind for a refused field-less resource', function (): void {
    Route::get('/json-fieldless-forbidden', [
        ReturnExpressionController::class,
        'jsonWrappedFieldlessForbiddenStatus',
    ]);
    app()->forgetScopedInstances();

    $schemas = generateSpec()['components']['schemas'] ?? [];
    app()->forgetScopedInstances();

    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['resource.fields-undeclared'],
        uriGlob: 'json-fieldless-forbidden',
    ));

    // The refusal has to precede both side effects, not merely the envelope: a finding would point
    // at a response the document never emits, and the component would be an unreferenced orphan.
    expect($result->findings)->toBe([])
        ->and($schemas)->not->toHaveKey('FieldlessForbiddenResource');
});

it('leaves a 204 wrapper status to the inline-JSON reader, which documents it without a body', function (): void {
    Route::get('/json-no-content-status', [ReturnExpressionController::class, 'jsonWrappedNoContentStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/json-no-content-status']['get']['responses'];

    // Core claims the call before the resource path sees it, so no envelope is attached at all.
    expect(successStatuses($responses))->toBe([204])
        ->and($responses['204'])->not->toHaveKey('content')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('lets an authored wrapper status win over the resourceful-route convention', function (): void {
    Route::post('/authored-widgets', [AuthoredStatusStoreController::class, 'store']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/authored-widgets', '202', 'post');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/authored-widgets']['post']['responses']))->toBe([202]);
});

it('keeps the resourceful-route convention when the wrapper authors no status', function (): void {
    Route::post('/conventional-widgets', [ConventionalStatusStoreController::class, 'store']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/conventional-widgets', '201', 'post');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/conventional-widgets']['post']['responses']))->toBe([201]);
});

it('leaves the resource paths that carry no wrapper on their conventional status', function (): void {
    Route::get('/author-typed', [ReturnExpressionController::class, 'staticMake']);
    Route::get('/author-attributed', [ReturnExpressionController::class, 'attributeWins']);

    $spec = generateSpec();

    expect(successStatuses($spec['paths']['/author-typed']['get']['responses']))->toBe([200])
        ->and(successStatuses($spec['paths']['/author-attributed']['get']['responses']))->toBe([200]);
});

it('leaves a non-resource response()->json(...) at the bare 200', function (): void {
    Route::get('/json-non-resource', [ReturnExpressionController::class, 'jsonWrappedNonResource']);

    $spec = generateSpec();
    $response = $spec['paths']['/json-non-resource']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

// endregion

// region #[ResponseResource] + an authored wrapper status

it('yields entirely when an attributed action wraps its resource in a non-2xx status', function (): void {
    Route::get('/attributed-forbidden', [ReturnExpressionController::class, 'attributedForbiddenStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/attributed-forbidden']['get']['responses'];

    // The attribute names the resource, not the status it rides on: a 403 cannot carry a success
    // envelope, so no 2xx carries content and nothing is registered for a resource never referenced.
    expect(successStatuses($responses))->toBe([200])
        ->and($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('honours a 2xx wrapper status on an attributed action, envelope included', function (): void {
    Route::get('/attributed-accepted', [ReturnExpressionController::class, 'attributedAcceptedStatus']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/attributed-accepted', '202');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/attributed-accepted']['get']['responses']))->toBe([202]);
});

it('reads the wrapper status even when the wrapped data resolves to nothing', function (): void {
    Route::get('/attributed-opaque', [ReturnExpressionController::class, 'attributedOpaqueForbiddenStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/attributed-opaque']['get']['responses'];

    // The shape #[ResponseResource] exists for: the data argument is unreadable, so only a
    // status-only read reaches the 403. Resolving the resource first would keep the phantom 200.
    expect(successStatuses($responses))->toBe([200])
        ->and($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('yields before the attribute\'s cardinality can emit a collection envelope', function (): void {
    Route::get('/attributed-collection-forbidden', [
        ReturnExpressionController::class,
        'attributedCollectionForbiddenStatus',
    ]);

    $spec = generateSpec();
    $responses = $spec['paths']['/attributed-collection-forbidden']['get']['responses'];

    expect($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('keeps the attributed resource at the conventional 200 when the status is not readable', function (): void {
    Route::get('/attributed-dynamic', [ReturnExpressionController::class, 'attributedDynamicStatus']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/attributed-dynamic');

    // An unreadable status must not cost the attribute its resource.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/attributed-dynamic']['get']['responses']))->toBe([200]);
});

it('drops the status claim, not the attributed resource, when the branches disagree', function (): void {
    Route::get('/attributed-mixed', [ReturnExpressionController::class, 'attributedMixedErrorAndBare']);

    $spec = generateSpec();
    $schema = successSchema($spec, '/attributed-mixed');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/attributed-mixed']['get']['responses']))->toBe([200]);
});

it('lets a lone authored status stand beside a sentinel return, which is no branch at all', function (): void {
    Route::get('/attributed-sentinel-forbidden', [
        ReturnExpressionController::class,
        'attributedSentinelAndForbidden',
    ]);

    $spec = generateSpec();
    $responses = $spec['paths']['/attributed-sentinel-forbidden']['get']['responses'];

    // The distinction the reconciliation rests on, and the one easiest to conflate: `return null;`
    // (and a bare `return;`) is an ignored sentinel skipped before any comparison, so the 403 is the
    // only status authored and the response yields. A branch returning a bare *resource* would author
    // no status and disagree instead, keeping the resource at the conventional 200 — the case above.
    expect(successStatuses($responses))->toBe([200])
        ->and($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('drops the status claim whichever branch order the disagreement arrives in', function (): void {
    Route::get('/attributed-success-then-error', [
        ReturnExpressionController::class,
        'attributedSuccessThenError',
    ]);

    $spec = generateSpec();
    $schema = successSchema($spec, '/attributed-success-then-error');

    // The mirror of the 403-first case, and the row that pins the all-branches-agree comparison: with
    // the success status first, a reconciliation that merely kept the last status read would carry the
    // 403 through and yield the resource away entirely. The resource has to survive at the
    // conventional 200 instead, because two disagreeing branches say nothing certain about the status.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths']['/attributed-success-then-error']['get']['responses']))->toBe([200]);
});

it('yields when every branch of an attributed action agrees on the same non-2xx status', function (): void {
    Route::get('/attributed-all-forbidden', [ReturnExpressionController::class, 'attributedAllForbidden']);

    $spec = generateSpec();
    $responses = $spec['paths']['/attributed-all-forbidden']['get']['responses'];

    expect($responses['200'])->not->toHaveKey('content')
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('pins a residual: any chain after json() still leaves the envelope on a phantom 200', function (): void {
    Route::get('/attributed-header-chain', [ReturnExpressionController::class, 'attributedHeaderChain']);

    $spec = generateSpec();
    $responses = $spec['paths']['/attributed-header-chain']['get']['responses'];
    $schema = successSchema($spec, '/attributed-header-chain');

    // Known-bad output, pinned so it cannot change unnoticed, not correct output. Only a bare
    // response()->json(...) is read for the status here, so *any* trailing link hides it, including
    // the ->additional() the inference path does unwrap. And the consequence is worse than there:
    // that path loses the resource with the status, so nothing is documented, whereas the attribute
    // names the resource regardless, leaving the envelope and its component on a 200 beside the
    // authored 403 the action actually returns. This bug's own shape, one chain link away.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($responses))->toBe([200])
        ->and($responses)->toHaveKey('403')
        ->and($spec['components']['schemas'])->toHaveKey('NestedAuthorResource');
});

it('drops a content-less attributed status on a non-resourceful route, leaving a body-less 200', function (string $action): void {
    Route::get('/attributed-content-less', [ReturnExpressionController::class, $action]);

    $spec = generateSpec();
    $responses = $spec['paths']['/attributed-content-less']['get']['responses'];

    // Core never sees the call (it skips actions carrying a primary-response authoring attribute),
    // so the authored status is dropped and the conventional 200 is all that is left. Losing the
    // status beats an envelope RFC 9110 forbids on either code.
    expect(successStatuses($responses))->toBe([200])
        ->and($responses['200'])->not->toHaveKey('content')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
})->with([
    'authored 204' => ['attributedNoContentStatus'],
    'authored 205' => ['attributedResetContentStatus'],
]);

it('lets the resourceful destroy() convention supply 204 for a dropped content-less status', function (string $controller): void {
    Route::delete('/attributed-widgets/{widget}', [$controller, 'destroy']);

    $spec = generateSpec();
    $responses = $spec['paths']['/attributed-widgets/{widget}']['delete']['responses'];

    // The route shape decides the outcome, so both halves of the matrix are pinned: on a DELETE the
    // convention names 204, which restores the authored 204 — and renames an authored 205 to it, a
    // status that action never returns. That mismatch is the convention's, not the attribute's: an
    // unattributed destroy() authoring 205 documents 204 today too.
    expect(successStatuses($responses))->toBe([204])
        ->and($responses['204'])->not->toHaveKey('content')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
})->with([
    'authored 204 (restored)' => [AttributedNoContentDestroyController::class],
    'authored 205 (renamed to 204)' => [AttributedResetContentDestroyController::class],
]);

it('does not resurrect the resourceful 201 for a class-attributed store() wrapped in a 403', function (): void {
    Route::post('/class-attributed-widgets', [ClassAttributedForbiddenStoreController::class, 'store']);

    $spec = generateSpec();
    $responses = $spec['paths']['/class-attributed-widgets']['post']['responses'];

    expect(successStatuses($responses))->toBe([201])
        ->and($responses['201'])->not->toHaveKey('content')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});

it('lets an authored 200 beat the resourceful 201 on an attributed store()', function (string $route, string $status): void {
    Route::post($route, [
        $status === '200' ? AttributedOkStoreController::class : AttributedAcceptedStoreController::class,
        'store',
    ]);

    $spec = generateSpec();
    $schema = successSchema($spec, $route, $status, 'post');

    // Reading the wrapper status makes it explicit, so the convention no longer renumbers it: an
    // attributed store() authoring 200 documents 200, where it used to document 201.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and(successStatuses($spec['paths'][$route]['post']['responses']))->toBe([(int) $status]);
})->with([
    'authored 200 over the conventional 201' => ['/attributed-ok-widgets', '200'],
    'authored 202 over the conventional 201' => ['/attributed-accepted-widgets', '202'],
]);

it('leaves neither a lint finding nor a component behind for a refused attributed resource', function (): void {
    Route::get('/attributed-fieldless-forbidden', [
        ReturnExpressionController::class,
        'attributedFieldlessForbiddenStatus',
    ]);
    app()->forgetScopedInstances();

    $schemas = generateSpec()['components']['schemas'] ?? [];
    app()->forgetScopedInstances();

    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['resource.fields-undeclared'],
        uriGlob: 'attributed-fieldless-forbidden',
    ));

    expect($result->findings)->toBe([])
        ->and($schemas)->not->toHaveKey('FieldlessForbiddenResource');
});

it('stays silent about an attributed action whose body it cannot read', function (): void {
    Route::get('/attributed-opaque-log', [ReturnExpressionController::class, 'attributedOpaqueForbiddenStatus']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    generateSpec();

    // The reader's refusal notice advises annotating the action with #[ResponseResource], which is
    // nonsense on an action that already carries it. The status-only read never notes.
    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'attributedOpaqueForbiddenStatus'),
    );

    expect($noted)->toBeFalse();
});

// endregion
