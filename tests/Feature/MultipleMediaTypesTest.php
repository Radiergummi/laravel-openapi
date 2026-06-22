<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Tests\Fixtures\RemoteMediaFixtureRequest;

uses()->group('openapi');

// region Fixture controllers — one action per scenario so routes don't bleed

class MultipleMediaTypesController extends Controller
{
    #[Response(
        status: 200,
        description: 'Document in either representation',
        schema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        mediaTypes: [MediaType::Json, MediaType::Yaml],
    )]
    public function response(): JsonResponse
    {
        return new JsonResponse();
    }

    #[RequestBody(mediaTypes: [MediaType::Json, MediaType::Yaml])]
    public function requestBodyAbsent(): JsonResponse
    {
        return new JsonResponse();
    }

    #[RequestBody(mediaTypes: [MediaType::Json, MediaType::FormUrlEncoded])]
    public function requestBodyPresent(RemoteMediaFixtureRequest $request): JsonResponse
    {
        return new JsonResponse();
    }

    #[RequestBody(mediaType: MediaType::TextPlain, mediaTypes: [MediaType::Json, MediaType::Yaml])]
    public function requestBodyPrecedence(): JsonResponse
    {
        return new JsonResponse();
    }

    #[RequestBody(mediaType: MediaType::Yaml, mediaTypes: [])]
    public function requestBodyEmptyList(): JsonResponse
    {
        return new JsonResponse();
    }

    #[Response(status: 200, description: 'Plain log', schema: ['type' => 'string'], mediaType: MediaType::TextPlain)]
    public function singleResponse(): JsonResponse
    {
        return new JsonResponse();
    }

    #[Response(status: 200, description: 'Default JSON', schema: ['type' => 'object'])]
    public function defaultResponse(): JsonResponse
    {
        return new JsonResponse();
    }

    #[Response(
        status: 200,
        description: 'List wins over scalar',
        schema: ['type' => 'string'],
        mediaType: MediaType::TextPlain,
        mediaTypes: [MediaType::Json, MediaType::Yaml],
    )]
    public function precedence(): JsonResponse
    {
        return new JsonResponse();
    }
}

// endregion

it('emits one content entry per declared media type on a response', function (): void {
    Route::get('/multi-media/response', [MultipleMediaTypesController::class, 'response']);

    $content = generateSpec()['paths']['/multi-media/response']['get']['responses']['200']['content'] ?? [];

    expect($content)->toHaveKey('application/json')
        ->and($content)->toHaveKey('application/yaml')
        ->and($content)->toHaveCount(2)
        ->and($content['application/json']['schema']['properties']['name']['type'])->toBe('string')
        ->and($content['application/yaml']['schema']['properties']['name']['type'])->toBe('string');
});

it('emits one content entry per declared media type on a request body with no auto body', function (): void {
    Route::post('/multi-media/request-absent', [MultipleMediaTypesController::class, 'requestBodyAbsent']);

    $content = generateSpec()['paths']['/multi-media/request-absent']['post']['requestBody']['content'] ?? [];

    expect($content)->toHaveKey('application/json')
        ->and($content)->toHaveKey('application/yaml')
        ->and($content)->toHaveCount(2)
        ->and($content['application/json']['schema']['type'])->toBe('object')
        ->and($content['application/yaml']['schema']['type'])->toBe('object');
});

it('fans out the resolved auto-body schema across every declared media type', function (): void {
    Route::post('/multi-media/request-present', [MultipleMediaTypesController::class, 'requestBodyPresent']);

    $content = generateSpec()['paths']['/multi-media/request-present']['post']['requestBody']['content'] ?? [];

    expect($content)->toHaveKey('application/json')
        ->and($content)->toHaveKey('application/x-www-form-urlencoded')
        ->and($content)->toHaveCount(2);

    $jsonRef = $content['application/json']['schema']['$ref'] ?? null;

    // The fan-out reuses the exact resolved $ref the auto-derive produced.
    expect($jsonRef)->not->toBeNull()
        ->and($content['application/x-www-form-urlencoded']['schema']['$ref'])->toBe($jsonRef);
});

it('leaves a single-mediaType declaration unchanged', function (): void {
    Route::get('/multi-media/single', [MultipleMediaTypesController::class, 'singleResponse']);

    $content = generateSpec()['paths']['/multi-media/single']['get']['responses']['200']['content'] ?? [];

    expect($content)->toHaveCount(1)
        ->and($content)->toHaveKey('text/plain')
        ->and($content['text/plain']['schema']['type'])->toBe('string');
});

it('defaults to a single application/json entry when no media type is declared', function (): void {
    Route::get('/multi-media/default', [MultipleMediaTypesController::class, 'defaultResponse']);

    $content = generateSpec()['paths']['/multi-media/default']['get']['responses']['200']['content'] ?? [];

    expect($content)->toHaveCount(1)
        ->and($content)->toHaveKey('application/json');
});

it('honours mediaTypes over a scalar mediaType when both are set', function (): void {
    Route::get('/multi-media/precedence', [MultipleMediaTypesController::class, 'precedence']);

    $content = generateSpec()['paths']['/multi-media/precedence']['get']['responses']['200']['content'] ?? [];

    expect($content)->toHaveCount(2)
        ->and($content)->toHaveKey('application/json')
        ->and($content)->toHaveKey('application/yaml')
        ->and($content)->not->toHaveKey('text/plain');
});

it('honours mediaTypes over a scalar mediaType on a request body when both are set', function (): void {
    Route::post('/multi-media/request-precedence', [MultipleMediaTypesController::class, 'requestBodyPrecedence']);

    $content = generateSpec()['paths']['/multi-media/request-precedence']['post']['requestBody']['content'] ?? [];

    expect($content)->toHaveCount(2)
        ->and($content)->toHaveKey('application/json')
        ->and($content)->toHaveKey('application/yaml')
        ->and($content)->not->toHaveKey('text/plain');
});

it('degrades an empty mediaTypes list to the single default media type', function (): void {
    Route::post('/multi-media/request-empty', [MultipleMediaTypesController::class, 'requestBodyEmptyList']);

    $content = generateSpec()['paths']['/multi-media/request-empty']['post']['requestBody']['content'] ?? [];

    expect($content)->toHaveCount(1)
        ->and($content)->toHaveKey('application/yaml');
});

it('does not mis-fire the duplicate-status lint rule on a multi-entry content map', function (): void {
    Route::get('/multi-media/response', [MultipleMediaTypesController::class, 'response']);

    $outcome = app(LintRunner::class)->run(
        new LintOptions(
            level: 3,
            only: ['response.duplicate-status'],
            uriGlob: 'multi-media/response*',
        ),
    );

    expect($outcome->findings)->toBe([]);
});
