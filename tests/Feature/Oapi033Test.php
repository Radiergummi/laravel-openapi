<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use App\Http\Resources\Internal\V0\Communication\ResearchInquiryResource;
use App\Models\ResearchInquiry;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\JsonApi\ResourceClassResolver;
use Radiergummi\OpenApi\Plugins\JsonApi\ResponseSchemaExtractor;
use Radiergummi\OpenApi\Plugins\JsonApi\SchemaFromApiResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\ContextFactory;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

uses()->group('openapi');

// ---------------------------------------------------------------------------
// Fixture controller — the @return PHPDoc carries the generic type argument
// ---------------------------------------------------------------------------

class PaginatorFixtureController extends Controller
{
    /**
     * @return LengthAwarePaginator<ResearchInquiry>
     */
    public function index(): LengthAwarePaginator
    {
        // Fixture only — never called.
        return new LengthAwarePaginator([], 0, 15);
    }

    /**
     * No generic annotation — paginator resolution returns null, so no resource
     * can be resolved for this endpoint.
     */
    public function indexUntyped(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }
}

// ---------------------------------------------------------------------------
// Helper: build a minimal ActionDescriptor for the fixture controller method
// ---------------------------------------------------------------------------

function oapi033MakeDescriptor(string $method): ActionDescriptor
{
    $route = new Route(['GET'], '/fixture', [PaginatorFixtureController::class, $method]);
    $controllerRef = new ReflectionClass(PaginatorFixtureController::class);
    $methodRef     = new ReflectionMethod(PaginatorFixtureController::class, $method);

    return new ActionDescriptor(
        route: $route,
        controller: $controllerRef,
        method: $methodRef,
        summary: null,
        description: null,
    );
}

function oapi033MakeExtractor(): ResponseSchemaExtractor
{
    $registry  = new ComponentSchemaRegistry();
    $resource  = new SchemaFromApiResource($registry);
    $resolver  = new ResourceClassResolver(new ArrayFindingsCollector());

    return new ResponseSchemaExtractor(
        resourceClassResolver: $resolver,
        schemaFromApiResource: $resource,
        logger: new NullLogger(),
        findings: new ArrayFindingsCollector(),
        docBlockFactory: DocBlockFactory::createInstance(),
        contextFactory: new ContextFactory(),
    );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('OAPI-033: LengthAwarePaginator<Model> in @return PHPDoc resolves to a typed collection envelope', function (): void {
    $extractor  = oapi033MakeExtractor();
    $descriptor = oapi033MakeDescriptor('index');

    $response = $extractor->resolvePrimaryResponse($descriptor);

    expect($response)->toBeInstanceOf(OA\Response::class)
        ->and($response->response)->toBe('200');

    // The response must carry application/vnd.api+json content.
    $content = $response->content;
    expect($content)->toBeArray()->and($content)->not->toBeEmpty();

    $mediaType = $content[0];
    expect($mediaType)->toBeInstanceOf(OA\MediaType::class)
        ->and($mediaType->mediaType)->toBe('application/vnd.api+json');

    // The envelope schema must be a collection (data is array, not a single ref).
    $envelope = $mediaType->schema;
    expect($envelope)->toBeInstanceOf(OA\Schema::class);

    $dataProperty = null;

    foreach ($envelope->properties as $prop) {
        if ($prop instanceof OA\Property && $prop->property === 'data') {
            $dataProperty = $prop;

            break;
        }
    }

    expect($dataProperty)->not->toBeNull()
        ->and($dataProperty->type)->toBe('array');

    // items must carry a $ref to the ResearchInquiry resource component.
    $items = $dataProperty->items;
    expect($items)->toBeInstanceOf(OA\Items::class)
        ->and($items->ref)->toContain('ResearchInquiry');
});

it('OAPI-033: LengthAwarePaginator without generic @return annotation falls through (returns null or normal result)', function (): void {
    $extractor  = oapi033MakeExtractor();
    $descriptor = oapi033MakeDescriptor('indexUntyped');

    // Without the @return generic, paginator resolution returns null; the
    // resolver chain returns null because LengthAwarePaginator is not a
    // recognised resource type, so the extraction returns null. The invariant
    // under test is that this path does NOT throw.
    $extractor->resolvePrimaryResponse($descriptor);

    // No assertion on the value — we only assert it doesn't throw.
    expect(true)->toBeTrue();
});

it('ResourceClassResolver::resourceFromModelClass maps ResearchInquiry to its resource', function (): void {
    $resolver = new ResourceClassResolver(new ArrayFindingsCollector());
    $result   = $resolver->resourceFromModelClass(ResearchInquiry::class);

    expect($result)->toBe(ResearchInquiryResource::class);
});
