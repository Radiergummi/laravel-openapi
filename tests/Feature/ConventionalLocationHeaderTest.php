<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\ResponseExample;
use Radiergummi\OpenApi\Attributes\ResponseHeader;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\PassthroughArticleResource;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses()->group('openapi');

/** Resourceful action names, so the route convention resolves the 201 for POST. */
class StreamedCreationController extends Controller
{
    public function store(): StreamedResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class BinaryFileCreationController extends Controller
{
    public function store(): BinaryFileResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class ResourceCreationController extends Controller
{
    public function store(): PassthroughArticleResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class AuthoredLocationStreamController extends Controller
{
    #[ResponseHeader(name: 'Location', status: 201, description: 'Authored location')]
    public function store(): StreamedResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class AuthoredCreationBodyController extends Controller
{
    #[Response(status: 201, description: 'Created', schema: ['type' => 'string'])]
    public function scalarPrimary(): array
    {
        return [];
    }

    #[Response(status: 201, description: 'Created', schema: ['type' => 'object'])]
    public function objectPrimary(): array
    {
        return [];
    }

    #[Response(status: 201, description: 'Created', schema: ['properties' => ['id' => ['type' => 'integer']]])]
    public function typelessPrimary(): array
    {
        return [];
    }

    #[Response(status: 201, description: 'Created')]
    public function contentlessPrimary(): array
    {
        return [];
    }

    /** An example on an otherwise content-less 201 scaffolds a media type carrying no schema. */
    #[Response(status: 201, description: 'Created')]
    #[ResponseExample(status: 201, name: 'created', value: ['id' => 1])]
    public function schemalessPrimary(): array
    {
        return [];
    }

    /**
     * The first 2xx attribute becomes the primary response, so declaring the 201 second is what
     * keeps it an additional response: the case only the applier-sited fix can reach.
     */
    #[Response(status: 200, description: 'OK', schema: ['type' => 'object'])]
    #[Response(status: 201, description: 'Created', schema: ['type' => 'string'])]
    public function scalarNonPrimary(): array
    {
        return [];
    }

    /** The mirror of {@see scalarNonPrimary()}: same shape, object body on the additional 201. */
    #[Response(status: 200, description: 'OK', schema: ['type' => 'object'])]
    #[Response(status: 201, description: 'Created', schema: ['type' => 'object'])]
    public function objectNonPrimary(): array
    {
        return [];
    }
}

function createdResponse(string $uri, string $verb = 'post'): mixed
{
    return generateSpec()['paths'][$uri][$verb]['responses']['201'] ?? null;
}

// region suppressed: the 201 body is positively scalar

it('drops the conventional Location header from a streamed 201', function (): void {
    Route::post('/conventional/streamed', [StreamedCreationController::class, 'store']);

    $response = createdResponse('/conventional/streamed');

    expect($response)->not->toBeNull()
        ->and($response['content'])->toHaveKey('application/octet-stream')
        ->and($response['headers'] ?? [])->not->toHaveKey('Location');
});

it('drops the conventional Location header from a binary-file 201', function (): void {
    Route::post('/conventional/binary', [BinaryFileCreationController::class, 'store']);

    $response = createdResponse('/conventional/binary');

    expect($response)->not->toBeNull()
        ->and($response['content'])->toHaveKey('application/octet-stream')
        ->and($response['headers'] ?? [])->not->toHaveKey('Location');
});

it('drops the conventional Location header from a scalar JSON 201', function (): void {
    Route::get('/conventional/scalar', [AuthoredCreationBodyController::class, 'scalarPrimary']);

    $response = createdResponse('/conventional/scalar', 'get');

    expect($response)->not->toBeNull()
        ->and($response['content']['application/json']['schema']['type'])->toBe('string')
        ->and($response['headers'] ?? [])->not->toHaveKey('Location');
});

it('drops the conventional Location header from a scalar non-primary 201', function (): void {
    Route::get('/conventional/scalar-additional', [AuthoredCreationBodyController::class, 'scalarNonPrimary'])
        ->middleware('throttle:60,1');

    $spec = generateSpec()['paths']['/conventional/scalar-additional']['get']['responses'];

    // The rate-limit pair attaches to the primary response only, so its placement proves the 200 is
    // primary and the 201 was reached by the per-response loop alone.
    expect($spec['200']['headers'] ?? [])->toHaveKey('X-RateLimit-Limit')
        ->and($spec['201']['headers'] ?? [])->not->toHaveKey('X-RateLimit-Limit')
        ->and($spec['201']['headers'] ?? [])->not->toHaveKey('Location');
});

// endregion

// region kept: no positive evidence of a scalar body

it('keeps the conventional Location header on a resource 201', function (): void {
    Route::post('/conventional/resource', [ResourceCreationController::class, 'store']);

    $response = createdResponse('/conventional/resource');

    expect($response)->not->toBeNull()
        ->and($response['headers']['Location']['schema'] ?? null)
        ->toBe(['type' => 'string', 'format' => 'uri-reference']);
});

it('keeps the conventional Location header on an object-bodied 201', function (): void {
    Route::get('/conventional/object', [AuthoredCreationBodyController::class, 'objectPrimary']);

    $response = createdResponse('/conventional/object', 'get');

    expect($response)->not->toBeNull()
        ->and($response['headers'] ?? [])->toHaveKey('Location');
});

// An example on a body-less response scaffolds a media type with no schema at all, so the content
// exists while carrying no evidence either way.
it('keeps the conventional Location header on a 201 whose media type carries no schema', function (): void {
    Route::get('/conventional/schemaless', [AuthoredCreationBodyController::class, 'schemalessPrimary']);

    $response = createdResponse('/conventional/schemaless', 'get');

    expect($response)->not->toBeNull()
        ->and($response['content']['application/json'])->not->toHaveKey('schema')
        ->and($response['content']['application/json'])->toHaveKey('examples')
        ->and($response['headers'] ?? [])->toHaveKey('Location');
});

it('keeps the conventional Location header on a 201 whose schema declares no type', function (): void {
    Route::get('/conventional/typeless', [AuthoredCreationBodyController::class, 'typelessPrimary']);

    $response = createdResponse('/conventional/typeless', 'get');

    expect($response)->not->toBeNull()
        ->and($response['content']['application/json']['schema'])->not->toHaveKey('type')
        ->and($response['headers'] ?? [])->toHaveKey('Location');
});

it('keeps the conventional Location header on an object-bodied non-primary 201', function (): void {
    Route::get('/conventional/object-additional', [AuthoredCreationBodyController::class, 'objectNonPrimary'])
        ->middleware('throttle:60,1');

    $spec = generateSpec()['paths']['/conventional/object-additional']['get']['responses'];

    expect($spec['200']['headers'] ?? [])->toHaveKey('X-RateLimit-Limit')
        ->and($spec['201']['headers'] ?? [])->not->toHaveKey('X-RateLimit-Limit')
        ->and($spec['201']['headers'] ?? [])->toHaveKey('Location');
});

// An all-quantifier over media types is vacuously true on an empty list, so a 201 with no content
// at all is the false negative the guard has to avoid.
it('keeps the conventional Location header on a content-less 201', function (): void {
    Route::get('/conventional/contentless', [AuthoredCreationBodyController::class, 'contentlessPrimary']);

    $response = createdResponse('/conventional/contentless', 'get');

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? null)->toBeNull()
        ->and($response['headers'] ?? [])->toHaveKey('Location');
});

// endregion

// region interaction with the sibling conventions

it('leaves an authored Location header on a streamed 201 untouched', function (): void {
    Route::post('/conventional/authored', [AuthoredLocationStreamController::class, 'store']);

    $response = createdResponse('/conventional/authored');

    expect($response)->not->toBeNull()
        ->and($response['headers']['Location']['description'] ?? null)->toBe('Authored location');
});

it('still emits the rate-limit headers on a throttled streamed 201', function (): void {
    Route::post('/conventional/throttled', [StreamedCreationController::class, 'store'])
        ->middleware('throttle:60,1');

    $response = createdResponse('/conventional/throttled');

    expect($response)->not->toBeNull()
        ->and($response['headers'])->toHaveKey('X-RateLimit-Limit')
        ->and($response['headers'])->toHaveKey('X-RateLimit-Remaining')
        ->and($response['headers'])->not->toHaveKey('Location');
});

// endregion
