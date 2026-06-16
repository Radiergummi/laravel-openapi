<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\ResponseExample;
use Radiergummi\OpenApi\Attributes\ResponseFile;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use RuntimeException;

uses()->group('openapi');

const RESPONSE_FILE_FIXTURE = 'tests/Fixtures/OpenApi/example_payloads/create_project.json';

class ResponseFileFixtureController extends Controller
{
    #[ResponseFile(RESPONSE_FILE_FIXTURE)]
    public function primary(): JsonResponse
    {
        return new JsonResponse();
    }

    #[Response(status: 422, description: 'Validation failed')]
    #[ResponseFile(RESPONSE_FILE_FIXTURE, status: 422)]
    public function explicitStatus(): JsonResponse
    {
        return new JsonResponse();
    }

    #[ResponseFile('tests/Fixtures/OpenApi/example_payloads/does-not-exist.json')]
    public function missingFile(): JsonResponse
    {
        return new JsonResponse();
    }

    #[ResponseFile('tests/Fixtures/OpenApi/example_payloads/malformed.json')]
    public function invalidJson(): JsonResponse
    {
        return new JsonResponse();
    }

    // A declared 204 is conventionally bodyless; the file must not scaffold a JSON body onto it.
    #[Response(status: 204, description: 'No Content')]
    #[ResponseFile(RESPONSE_FILE_FIXTURE, status: 204)]
    public function noContent(): HttpResponse
    {
        return new HttpResponse(status: 204);
    }

    #[ResponseFile(RESPONSE_FILE_FIXTURE, status: 418)]
    public function noMatchingStatus(): JsonResponse
    {
        return new JsonResponse();
    }

    // Both target the 200 response; named examples and a singular example are mutually exclusive
    // on one media type, so the pre-existing named #[ResponseExample] wins and the file is skipped.
    #[ResponseExample(status: 200, name: 'inline', value: ['from' => 'attribute'])]
    #[ResponseFile(RESPONSE_FILE_FIXTURE)]
    public function collision(): JsonResponse
    {
        return new JsonResponse();
    }
}

function responseFileSpec(string $method, string $uri): array
{
    Route::get($uri, [ResponseFileFixtureController::class, $method]);

    return generateSpec();
}

it('attaches the file contents as the primary response example by default', function (): void {
    $spec = responseFileSpec('primary', '/oa-40/primary');

    $example = $spec['paths']['/oa-40/primary']['get']['responses']['200']['content']['application/json']['example'];

    expect($example)
        ->toBe([
            'name' => 'Aerospace Q1 Sourcing',
            'description' => 'Suppliers for titanium fasteners',
            'keywords' => ['aerospace', 'titanium', 'fasteners'],
        ]);
});

it('targets a declared response when status is set', function (): void {
    $spec = responseFileSpec('explicitStatus', '/oa-40/explicit');

    $response = $spec['paths']['/oa-40/explicit']['get']['responses'];

    expect($response['422']['content']['application/json']['example']['name'])
        ->toBe('Aerospace Q1 Sourcing')
        // The 200 does not carry the file example.
        ->and($response['200']['content']['application/json']['example'] ?? null)->toBeNull();
});

it('throws a clear error when the file is missing', function (): void {
    responseFileSpec('missingFile', '/oa-40/missing');
})->throws(RuntimeException::class, 'not found or unreadable');

it('throws a clear error when the file is not valid JSON', function (): void {
    responseFileSpec('invalidJson', '/oa-40/invalid');
})->throws(RuntimeException::class, 'invalid JSON');

it('does not scaffold a body example onto a conventionally bodyless status', function (): void {
    $spec = responseFileSpec('noContent', '/oa-40/no-content');

    $response = $spec['paths']['/oa-40/no-content']['get']['responses']['204'];

    expect($response['content'] ?? null)->toBeNull();
});

it('drops the file silently when status matches no declared response', function (): void {
    $spec = responseFileSpec('noMatchingStatus', '/oa-40/no-match');

    $content = $spec['paths']['/oa-40/no-match']['get']['responses']['200']['content'] ?? [];

    expect($content['application/json']['example'] ?? null)->toBeNull();
});

it('skips the file when named examples already occupy the media type', function (): void {
    $spec = responseFileSpec('collision', '/oa-40/collision');

    $media = $spec['paths']['/oa-40/collision']['get']['responses']['200']['content']['application/json'];

    // The named example stays; the singular example is not set, so validate() stays happy.
    expect($media['examples'])
        ->toHaveKey('inline')
        ->and($media['example'] ?? null)->toBeNull();
});

it('produces a swagger-php-valid document with a #[ResponseFile] example', function (): void {
    Route::get('/oa-40/valid-primary', [ResponseFileFixtureController::class, 'primary']);
    Route::get('/oa-40/valid-collision', [ResponseFileFixtureController::class, 'collision']);

    $registry = app(SpecRegistry::class);
    $document = app(OpenApiGenerator::class)->generate($registry->default(), app()->environment());

    expect($document->validate())->toBeTrue();
});
