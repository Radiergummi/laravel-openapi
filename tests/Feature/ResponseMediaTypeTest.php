<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Enums\MediaType;

uses()->group('openapi');

// region Fixture controller — one action per non-JSON media type

/**
 * #112: non-JSON success bodies keyed under the correct media type via
 * #[Response(mediaType: ...)] — a log as text/plain, a UI page as text/html,
 * a combined spec as application/yaml, a download as application/octet-stream.
 */
class NonJsonMediaTypeController extends Controller
{
    #[Response(status: 200, description: 'Run log', schema: ['type' => 'string'], mediaType: MediaType::TextPlain)]
    public function log(): HttpResponse
    {
        return new HttpResponse('log');
    }

    #[Response(status: 200, description: 'Swagger UI page', schema: ['type' => 'string'], mediaType: MediaType::TextHtml)]
    public function ui(): HttpResponse
    {
        return new HttpResponse('<html></html>');
    }

    #[Response(status: 200, description: 'Combined OpenAPI document', schema: ['type' => 'string'], mediaType: MediaType::Yaml)]
    public function yaml(): HttpResponse
    {
        return new HttpResponse('openapi: 3.1.0');
    }

    #[Response(status: 200, description: 'Binary download', schema: ['type' => 'string', 'format' => 'binary'], mediaType: MediaType::OctetStream)]
    public function download(): HttpResponse
    {
        return new HttpResponse('');
    }
}

// endregion

it('#112: keys non-JSON response bodies under their declared media type', function (string $action, string $expectedMediaType): void {
    Route::get("/media-type/{$action}", [NonJsonMediaTypeController::class, $action]);

    $spec = generateSpec();

    $content = $spec['paths']["/media-type/{$action}"]['get']['responses']['200']['content'] ?? [];

    expect($content)->toHaveKey($expectedMediaType)
        ->and($content)->not->toHaveKey('application/json')
        ->and($content[$expectedMediaType]['schema']['type'])->toBe('string');
})->with([
    'text/plain'               => ['log', 'text/plain'],
    'text/html'                => ['ui', 'text/html'],
    'application/yaml'         => ['yaml', 'application/yaml'],
    'application/octet-stream' => ['download', 'application/octet-stream'],
]);
