<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Attributes\Response;
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

function httpResponse(string $uri, string $status): mixed
{
    return generateSpec()['paths'][$uri]['get']['responses'][$status] ?? null;
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
