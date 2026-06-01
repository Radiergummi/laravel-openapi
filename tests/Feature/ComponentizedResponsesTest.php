<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\Tests\Fixtures\TeapotException;
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
        ->and($spec['components']['responses'])->toHaveKey('TooManyRequests')
        ->and($spec['components']['responses'])->toHaveKey('Conflict');

    $responses = $spec['paths']['/oa-p2/create']['get']['responses'];

    expect($responses['401'])
        ->toHaveKey('$ref')
        ->and($responses['401']['$ref'])->toBe('#/components/responses/Unauthorized')
        ->and($responses['403'])->toHaveKey('$ref')
        ->and($responses['403']['$ref'])->toBe('#/components/responses/Forbidden')
        ->and($responses['429'])->toHaveKey('$ref')
        ->and($responses['429']['$ref'])->toBe('#/components/responses/TooManyRequests')
        ->and($responses['409'])->toHaveKey('$ref')
        ->and($responses['409']['$ref'])->toBe('#/components/responses/Conflict');
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
            static fn(string $key): bool => $key === 'Unauthorized',
        ),
    );
    expect($unauthorizedCount)->toBe(1);
});
