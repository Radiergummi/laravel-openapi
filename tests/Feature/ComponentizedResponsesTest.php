<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Core\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\ThrowsExtractor;
use Radiergummi\OpenApi\Tests\Fixtures\TeapotException;
use ReflectionMethod;
use RuntimeException;

uses()->group('openapi');

#[ExceptionResponse(status: 409, description: 'Resource already exists')]
class ConflictException extends RuntimeException {}

class ComponentizedResponsesFixtureController extends Controller
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

it('OAPI-018: known status codes produce components.responses entries', function (): void {
    RouteFacade::get(
        '/oa-p2/create',
        [ComponentizedResponsesFixtureController::class, 'createAction'],
    )->middleware(['auth:api', 'scope:projects', 'throttle:api']);

    $spec = generateSpec();

    expect($spec['components'])
        ->toHaveKey('responses')
        ->and($spec['components']['responses'])->toHaveKey('Unauthorized')
        ->and($spec['components']['responses'])->toHaveKey('Forbidden')
        ->and($spec['components']['responses'])->toHaveKey('TooManyRequests');

    $responses = $spec['paths']['/oa-p2/create']['get']['responses'];

    expect($responses['401'])
        ->toHaveKey('$ref')
        ->and($responses['401']['$ref'])->toBe('#/components/responses/Unauthorized')
        ->and($responses['403'])->toHaveKey('$ref')
        ->and($responses['403']['$ref'])->toBe('#/components/responses/Forbidden')
        ->and($responses['429'])->toHaveKey('$ref')
        ->and($responses['429']['$ref'])->toBe('#/components/responses/TooManyRequests');
});

it('OAPI-018: unknown status codes are still inlined (no component name mapped)', function (): void {
    RouteFacade::get(
        '/oa-p2/teapot',
        [ComponentizedResponsesFixtureController::class, 'teapotAction'],
    );

    $spec = generateSpec();

    $responses = $spec['paths']['/oa-p2/teapot']['get']['responses'];

    expect($responses)
        ->toHaveKey('418')
        ->and($responses['418'])->not
        ->toHaveKey('$ref')
        ->and($responses['418']['description'])->toBe("I'm a teapot");
});

it('OAPI-018: each additional operation reuses the same component ref (deduplication)', function (): void {
    RouteFacade::get('/oa-p2/op1', [ComponentizedResponsesFixtureController::class, 'createAction'])
        ->middleware(['auth:api']);
    RouteFacade::get('/oa-p2/op2', [ComponentizedResponsesFixtureController::class, 'createAction'])
        ->middleware(['auth:api']);

    $spec = generateSpec();

    $responses1 = $spec['paths']['/oa-p2/op1']['get']['responses'];
    $responses2 = $spec['paths']['/oa-p2/op2']['get']['responses'];

    expect($responses1['401']['$ref'])
        ->toBe('#/components/responses/Unauthorized')
        ->and($responses2['401']['$ref'])->toBe('#/components/responses/Unauthorized');

    $allResponses = $spec['components']['responses'];
    $unauthorizedCount = count(
        array_filter(
            array_keys($allResponses),
            static fn(string $k): bool => $k === 'Unauthorized',
        ),
    );
    expect($unauthorizedCount)->toBe(1);
});

// TODO(tracker §5): the OAPI-021 cases below call StandardResponsesExtractor directly;
// rewrite to drive openapi:generate and assert on the produced spec.

it('OAPI-021: #[ExceptionResponse] on an exception class is resolved by the extractor', function (): void {
    $reflection = new ReflectionMethod(ComponentizedResponsesFixtureController::class, 'createAction');
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

    $conflict = array_values(array_filter($responses, static fn($r) => $r->response === '409'))[0];
    expect($conflict->ref)->toBe('#/components/responses/Conflict');
});

it('OAPI-021: deprecated #[Throws] alias is still resolved correctly', function (): void {
    $reflection = new ReflectionMethod(ComponentizedResponsesFixtureController::class, 'teapotAction');
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
