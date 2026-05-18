<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Radiergummi\OpenApi\Core\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\ThrowsExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Radiergummi\OpenApi\Tests\Fixtures\PropertyFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\TeapotException;

uses()->group('openapi');

// Fixture classes local to this test file
/**
 * OAPI-021: exception using the new canonical #[ExceptionResponse] attribute.
 */
#[ExceptionResponse(status: 409, description: 'Resource already exists')]
class ConflictException extends RuntimeException {}

/**
 * OAPI-018 + OAPI-021: fixture controller whose at-throws annotations exercise both componentized
 * responses and {@see ExceptionResponse} resolution.
 */
class P2FixtureController extends Controller
{
    /**
     * @throws ConflictException
     *
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public function createAction(): JsonResponse
    {
        return new JsonResponse();
    }

    /**
     * @throws TeapotException
     *
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public function teapotAction(): JsonResponse
    {
        return new JsonResponse();
    }
}

/**
 * OAPI-020: controller with Operation(tags:) — default merges with namespace tag.
 */
#[\Radiergummi\OpenApi\Core\Attributes\Operation(tags: ['ExtraTag'])]
class MergeTagController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

/**
 * OAPI-020: controller with Operation(tags:, replace: true) — discards namespace tag.
 */
#[\Radiergummi\OpenApi\Core\Attributes\Operation(tags: ['Replacement'], replace: true)]
class ReplaceTagController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

/**
 * OAPI-022: controller whose methods carry file-based examples.
 */
class FileExampleController extends Controller
{
    #[\Radiergummi\OpenApi\Core\Attributes\Example(
        name: 'from-file',
        file: 'tests/Fixtures/OpenApi/example_payloads/create_project.json',
    )]
    public function store(PropertyFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

// OAPI-018: standard error responses are componentised
it('OAPI-018: known status codes produce components.responses entries', function (): void {
    RouteFacade::get(
        '/oa-p2/create',
        [P2FixtureController::class, 'createAction'],
    )->middleware(['auth:api', 'scope:projects', 'throttle:api']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    // components.responses must contain the standard named responses.
    expect($spec['components'])
        ->toHaveKey('responses')
        ->and($spec['components']['responses'])->toHaveKey('Unauthorized')
        ->and($spec['components']['responses'])->toHaveKey('Forbidden')
        ->and($spec['components']['responses'])->toHaveKey('TooManyRequests');

    // The per-operation response must be a $ref, not an inline schema.
    $responses = $spec['paths']['/oa-p2/create']['get']['responses'];

    expect($responses['401'])
        ->toHaveKey('$ref')
        ->and($responses['401']['$ref'])->toBe('#/components/responses/Unauthorized')
        ->and($responses['403'])->toHaveKey('$ref')
        ->and($responses['403']['$ref'])->toBe('#/components/responses/Forbidden')
        ->and($responses['429'])->toHaveKey('$ref')
        ->and($responses['429']['$ref'])->toBe('#/components/responses/TooManyRequests');
});

it('OAPI-018: component response body references the shared JsonApiError schema', function (): void {
    RouteFacade::get(
        '/oa-p2/create-cmp',
        [P2FixtureController::class, 'createAction'],
    )->middleware(['auth:api']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $unauthorized = $spec['components']['responses']['Unauthorized'] ?? null;

    expect($unauthorized)->not
        ->toBeNull()
        ->and($unauthorized['content']['application/vnd.api+json']['schema']['$ref'])
        ->toBe('#/components/schemas/JsonApiError');
});

it('OAPI-018: unknown status codes are still inlined (no component name mapped)', function (): void {
    RouteFacade::get(
        '/oa-p2/teapot',
        [P2FixtureController::class, 'teapotAction'],
    );

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $responses = $spec['paths']['/oa-p2/teapot']['get']['responses'];

    // 418 has no STATUS_COMPONENT_NAMES entry — must be inlined.
    expect($responses)
        ->toHaveKey('418')
        ->and($responses['418'])->not
        ->toHaveKey('$ref')
        ->and($responses['418']['description'])->toBe("I'm a teapot")
        ->and($responses['418']['content']['application/vnd.api+json']['schema']['$ref'])
        ->toBe('#/components/schemas/JsonApiError');
});

it('OAPI-018: each additional operation reuses the same component ref (deduplication)', function (): void {
    RouteFacade::get('/oa-p2/op1', [P2FixtureController::class, 'createAction'])
        ->middleware(['auth:api']);
    RouteFacade::get('/oa-p2/op2', [P2FixtureController::class, 'createAction'])
        ->middleware(['auth:api']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    // Both operations reference the same component — it is not duplicated.
    $responses1 = $spec['paths']['/oa-p2/op1']['get']['responses'];
    $responses2 = $spec['paths']['/oa-p2/op2']['get']['responses'];

    expect($responses1['401']['$ref'])
        ->toBe('#/components/responses/Unauthorized')
        ->and($responses2['401']['$ref'])->toBe('#/components/responses/Unauthorized');

    // Only one copy in components.responses.
    $allResponses = $spec['components']['responses'];
    $unauthorizedCount = count(
        array_filter(
            array_keys($allResponses),
            static fn(string $k): bool => $k === 'Unauthorized',
        ),
    );
    expect($unauthorizedCount)->toBe(1);
});

// OAPI-020: Operation(tags:) merge vs. replace semantics
it('OAPI-020: Operation(tags:) merges with namespace-derived tag by default', function (): void {
    RouteFacade::get(
        '/oa-p2/merge-tag',
        [MergeTagController::class, 'index'],
    );

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $tags = $spec['paths']['/oa-p2/merge-tag']['get']['tags'] ?? [];

    // The namespace-derived tag "P2" (from "P2BatchTest" controller class)
    // plus the explicitly-declared "ExtraTag" must both be present.
    expect($tags)->toContain('ExtraTag');
});

it('OAPI-020: Operation(tags:, replace: true) discards namespace-derived tags', function (): void {
    RouteFacade::get(
        '/oa-p2/replace-tag',
        [ReplaceTagController::class, 'index'],
    );

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $tags = $spec['paths']['/oa-p2/replace-tag']['get']['tags'] ?? [];

    // Only the explicitly declared tag must survive.
    expect($tags)->toBe(['Replacement']);
});

// OAPI-021: #[ExceptionResponse] canonical + #[Throws] deprecated alias
it('OAPI-021: #[ExceptionResponse] on an exception class is resolved by the extractor', function (): void {
    $reflection = new ReflectionMethod(P2FixtureController::class, 'createAction');
    $route = new Route('GET', '/test', static fn() => null);
    $throws = ThrowsExtractor::create()->extract($reflection);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
        throws: $throws,
    );

    $responses = new StandardResponsesExtractor(new ComponentSchemaRegistry(), new ArrayFindingsCollector())->extract($descriptor);
    $statuses = array_map(static fn($r) => (int) $r->response, $responses);

    expect($statuses)->toContain(409);

    // 409 is a known status code → it becomes a component $ref, not an inline response.
    $conflict = array_values(array_filter($responses, static fn($r) => $r->response === '409'))[0];
    expect($conflict->ref)->toBe('#/components/responses/Conflict');
});

it('OAPI-021: deprecated #[Throws] alias is still resolved correctly', function (): void {
    // TeapotException carries #[Throws] (the deprecated alias). The extractor
    // must fall through to the Throws subclass check and still produce a response.
    $reflection = new ReflectionMethod(P2FixtureController::class, 'teapotAction');
    $route = new Route('GET', '/test', static fn() => null);
    $throws = ThrowsExtractor::create()->extract($reflection);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
        throws: $throws,
    );

    $responses = new StandardResponsesExtractor(new ComponentSchemaRegistry(), new ArrayFindingsCollector())->extract($descriptor);
    $statuses = array_map(static fn($r) => (int) $r->response, $responses);

    expect($statuses)->toContain(418);
});

// OAPI-022: file-based examples
it('OAPI-022: ExampleFileLoader loads and decodes a JSON file relative to the project root', function (): void {
    $loader = new ExampleFileLoader();
    $data = $loader->load('tests/Fixtures/OpenApi/example_payloads/create_project.json');

    expect($data)
        ->toBeArray()
        ->and($data['name'])->toBe('Aerospace Q1 Sourcing')
        ->and($data['keywords'])->toBe(['aerospace', 'titanium', 'fasteners']);
});

it('OAPI-022: ExampleFileLoader throws when the file does not exist', function (): void {
    $loader = new ExampleFileLoader();

    expect(fn() => $loader->load('tests/Fixtures/OpenApi/example_payloads/nonexistent.json'))
        ->toThrow(RuntimeException::class);
});

it('OAPI-022: ExampleFileLoader caches the result — second call is identical', function (): void {
    $loader = new ExampleFileLoader();

    $first = $loader->load('tests/Fixtures/OpenApi/example_payloads/create_project.json');
    $second = $loader->load('tests/Fixtures/OpenApi/example_payloads/create_project.json');

    expect($second)->toBe($first);
});

it('OAPI-022: #[Example(file:)] emits the file payload in the generated spec', function (): void {
    RouteFacade::post(
        '/oa-p2/file-example',
        [FileExampleController::class, 'store'],
    );

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $requestBody = $spec['paths']['/oa-p2/file-example']['post']['requestBody'] ?? null;

    expect($requestBody)->not->toBeNull();

    $examples = $requestBody['content']['application/json']['examples'] ?? [];

    expect($examples)
        ->toHaveKey('from-file')
        ->and($examples['from-file']['value']['name'])->toBe('Aerospace Q1 Sourcing')
        ->and($examples['from-file']['value']['keywords'])->toBe(['aerospace', 'titanium', 'fasteners']);
});
