<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses()->group('openapi');

/** An app subclass of RedirectResponse, to prove the read matches subclasses (`is_a(..., true)`). */
class AppRedirectResponse extends RedirectResponse {}

class HttpResponseTypeController extends Controller
{
    public function redirect(): RedirectResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function appRedirect(): AppRedirectResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function streamed(): StreamedResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function binaryFile(): BinaryFileResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function nullableRedirect(): ?RedirectResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function redirectUnion(): RedirectResponse|JsonResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    #[Response(status: 200, description: 'A JSON payload', schema: ['type' => 'object'])]
    public function streamedWithOverride(): StreamedResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function json(): JsonResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function response(): SymfonyResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

/** Resourceful action names, so the route convention resolves a success status for each verb. */
class RedirectResourceController extends Controller
{
    public function store(): RedirectResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function update(): RedirectResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function destroy(): RedirectResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class StreamedResourceController extends Controller
{
    public function store(): StreamedResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function update(): StreamedResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function destroy(): StreamedResponse
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

function httpResponse(string $uri, string $status): mixed
{
    return generateSpec()['paths'][$uri]['get']['responses'][$status] ?? null;
}

/** Every response of one operation, keyed by status, for the verbs the sibling helper cannot reach. */
function httpResponses(string $uri, string $verb): mixed
{
    return generateSpec()['paths'][$uri][$verb]['responses'] ?? [];
}

it('documents a RedirectResponse as a 302 with a Location header', function (): void {
    Route::get('/http/redirect', [HttpResponseTypeController::class, 'redirect']);

    $response = httpResponse('/http/redirect', '302');

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('Found')
        ->and($response['content'] ?? null)->toBeNull()
        ->and($response['headers']['Location']['schema']['type'])->toBe('string')
        ->and($response['headers']['Location']['schema']['format'])->toBe('uri');

    // No synthetic 200 alongside the 302.
    expect(httpResponse('/http/redirect', '200'))->toBeNull();
});

it('reads a RedirectResponse app subclass the same way', function (): void {
    Route::get('/http/app-redirect', [HttpResponseTypeController::class, 'appRedirect']);

    $response = httpResponse('/http/app-redirect', '302');

    expect($response)->not->toBeNull()
        ->and($response['headers']['Location']['schema']['format'])->toBe('uri');
});

it('documents a StreamedResponse as a binary 200', function (): void {
    Route::get('/http/streamed', [HttpResponseTypeController::class, 'streamed']);

    $response = httpResponse('/http/streamed', '200');

    expect($response)->not->toBeNull()
        ->and($response['content'])->toHaveKey('application/octet-stream')
        ->and($response['content'])->not->toHaveKey('application/json')
        ->and($response['content']['application/octet-stream']['schema'])
        ->toBe(['type' => 'string', 'format' => 'binary']);
});

it('documents a BinaryFileResponse as a binary 200', function (): void {
    Route::get('/http/binary', [HttpResponseTypeController::class, 'binaryFile']);

    $response = httpResponse('/http/binary', '200');

    expect($response)->not->toBeNull()
        ->and($response['content']['application/octet-stream']['schema'])
        ->toBe(['type' => 'string', 'format' => 'binary']);
});

// Pins the nullable-vs-union asymmetry: `?RedirectResponse` (nullable) stays a single-member
// shape and IS claimed, but `RedirectResponse|X` written as an explicit union carries more than
// one contract and is refused.
it('claims a nullable RedirectResponse (?T is not a union)', function (): void {
    Route::get('/http/nullable-redirect', [HttpResponseTypeController::class, 'nullableRedirect']);

    $response = httpResponse('/http/nullable-redirect', '302');

    expect($response)->not->toBeNull()
        ->and($response['headers']['Location']['schema']['format'])->toBe('uri');
});

it('refuses a union that includes a framework HTTP response type', function (): void {
    Route::get('/http/union', [HttpResponseTypeController::class, 'redirectUnion']);

    // Degrades to the prior behaviour: a synthetic empty 200, no 302, no octet-stream.
    $response = httpResponse('/http/union', '200');

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/octet-stream')
        ->and(httpResponse('/http/union', '302'))->toBeNull();
});

it('lets an explicit #[Response] win over the binary 200', function (): void {
    Route::get('/http/streamed-override', [HttpResponseTypeController::class, 'streamedWithOverride']);

    $response = httpResponse('/http/streamed-override', '200');

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('A JSON payload')
        ->and($response['content'])->toHaveKey('application/json')
        ->and($response['content'])->not->toHaveKey('application/octet-stream');
});

it(
    'keeps the 302 and its Location header on a resourceful action, over the convention status',
    function (string $action, string $verb, string $uri): void {
        Route::{$verb}($uri, [RedirectResourceController::class, $action]);

        $responses = httpResponses($uri, $verb);

        // The convention would rewrite the status (201/200/204) and, for destroy, discard the
        // Location with it; the type-derived 302 outranks it.
        expect($responses)->toHaveKey('302')
            ->and($responses)->not->toHaveKey('201')
            ->and($responses)->not->toHaveKey('204')
            ->and($responses['302']['headers']['Location']['schema']['format'])->toBe('uri');
    },
)->with([
    'store (201 convention)' => ['store', 'post', '/http/redirect-resource'],
    'update (200 convention)' => ['update', 'put', '/http/redirect-resource/{id}'],
    'destroy (204 convention)' => ['destroy', 'delete', '/http/redirect-resource/{id}/delete'],
]);

it(
    'lets the route convention promote the binary status, keeping the octet-stream body',
    function (string $action, string $verb, string $uri, string $expectedStatus): void {
        Route::{$verb}($uri, [StreamedResourceController::class, $action]);

        $responses = httpResponses($uri, $verb);

        // The binary 200 is a fallback, not a status read from the action, so a store() streaming
        // its result is a 201. A destroy() keeps the 200: the 204 convention yields to a body.
        expect($responses)->toHaveKey($expectedStatus)
            ->and($responses[$expectedStatus]['content']['application/octet-stream']['schema'])
            ->toBe(['type' => 'string', 'format' => 'binary']);
    },
)->with([
    'store promotes to 201' => ['store', 'post', '/http/stream-resource', '201'],
    'update stays 200' => ['update', 'put', '/http/stream-resource/{id}', '200'],
    'destroy keeps the body over the 204' => ['destroy', 'delete', '/http/stream-resource/{id}/delete', '200'],
]);

it('does not emit response.no-success for a redirect-only operation', function (): void {
    Route::get('/http/redirect-lint', [HttpResponseTypeController::class, 'redirect']);
    app()->forgetScopedInstances();

    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['response.no-success'],
        uriGlob: 'http/redirect-lint',
    ));

    expect($result->findings)->toBe([]);
});

it('does not claim a JsonResponse or a bare Response return', function (string $method, string $uri): void {
    Route::get($uri, [HttpResponseTypeController::class, $method]);

    // Neither is a redirect/streamed/binary type, so this resolver leaves them alone: no 302 and
    // no forced octet-stream body.
    expect(httpResponse($uri, '302'))->toBeNull()
        ->and(httpResponse($uri, '200')['content']['application/octet-stream'] ?? null)->toBeNull();
})->with([
    'JsonResponse' => ['json', '/http/json'],
    'Response' => ['response', '/http/response'],
]);
