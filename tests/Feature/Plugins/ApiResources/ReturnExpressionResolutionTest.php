<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceResponseAmbiguous;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\LiteralOnlyResource;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\NestedAuthorResource;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

use function array_any;
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

    private function authors(): AnonymousResourceCollection
    {
        throw new LogicException('Fixture helper; never invoked.');
    }
}

/**
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function successSchema(array $spec, string $path): array
{
    $schema = $spec['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull();

    return $schema;
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

it('resolves a resource wrapped in response()->json(X::make(...), status)', function (): void {
    Route::get('/json-single', [ReturnExpressionController::class, 'jsonWrappedSingle']);

    $spec = generateSpec();
    $responses = $spec['paths']['/json-single']['get']['responses'];
    $schema = successSchema($spec, '/json-single');

    // The resource $ref wins over the empty inline-JSON shape; only one 2xx is emitted.
    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and($spec['components']['schemas']['NestedAuthorResource']['properties'])->toHaveKeys(['id', 'name'])
        ->and($responses)->not->toHaveKey('201');
});

it('resolves a collection wrapped in response()->json(X::collection(...), status)', function (): void {
    Route::get('/json-collection', [ReturnExpressionController::class, 'jsonWrappedCollection']);

    $schema = successSchema(generateSpec(), '/json-collection');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
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
