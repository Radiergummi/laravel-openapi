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
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi');

// region Fixture controllers — one per scenario

/**
 * OAPI-024: SSE endpoint with an author-supplied per-event schema via
 * #[Response(status: 200, schema: [...], mediaType: 'text/event-stream')].
 * The explicit #[Response] overrides the auto-detected primary response.
 */
class StreamingWithSchemaOrderedController extends Controller
{
    /**
     * Stream supplier match events — schema declared in constructor order.
     */
    #[\Radiergummi\OpenApi\Core\Attributes\Response(
        status: 200,
        description: 'Server-Sent Events — one JSON object per line',
        schema: [
            'type' => 'object',
            'properties' => [
                'type'    => ['type' => 'string', 'enum' => ['match', 'done']],
                'payload' => ['type' => 'object'],
            ],
        ],
        mediaType: \Radiergummi\OpenApi\Core\Enums\MediaType::EventStream,
    )]
    public function stream(): StreamedResponse
    {
        return new StreamedResponse(static function (): void {});
    }
}

/**
 * OAPI-024: non-streaming endpoint — must NOT receive text/event-stream.
 */
class NonStreamingController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

// NOTE: StreamedResponse return-type auto-detection and #[Operation(streaming:)]
// content-type auto-emission were provided by the JSON:API plugin's
// PrimaryResponseResolver, which is not part of this package. Those scenarios
// (formerly OAPI-024 auto-detection tests) were removed during extraction.
// Streaming endpoints in this package declare their media type explicitly via
// #[Response(mediaType: MediaType::EventStream, ...)] — covered below.

// endregion

// region OAPI-024: #[Response(status: 200, mediaType: 'text/event-stream', schema: [...])]

it('OAPI-024: explicit Response attribute with text/event-stream mediaType and schema overrides auto-detection', function (): void {
    Route::get('/oa-024/stream-with-schema', [StreamingWithSchemaOrderedController::class, 'stream']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $response = $spec['paths']['/oa-024/stream-with-schema']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('Server-Sent Events — one JSON object per line')
        ->and($response['content'])->toHaveKey('text/event-stream');

    $schema = $response['content']['text/event-stream']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties']['type']['enum'])->toBe(['match', 'done']);
});

// endregion

// region OAPI-024: non-streaming endpoints are unaffected

it('OAPI-024: non-streaming endpoint does not receive text/event-stream', function (): void {
    Route::get('/oa-024/non-streaming', [NonStreamingController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $response = $spec['paths']['/oa-024/non-streaming']['get']['responses']['200'] ?? null;

    // No 200 body for unresolvable JsonResponse — falls back to placeholder.
    // What matters is that text/event-stream is not injected.
    $content = $response['content'] ?? [];

    expect($content)->not->toHaveKey('text/event-stream');
});

// endregion
