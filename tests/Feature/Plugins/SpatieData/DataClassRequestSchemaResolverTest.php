<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\FileUploadFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\NestedFileUploadFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;

use function ltrim;
use function str_replace;

uses()->group('openapi', 'plugin:spatie-data');

class DataClassRequestController extends Controller
{
    public function store(ScalarOnlyData $data): JsonResponse
    {
        return new JsonResponse();
    }

    public function upload(FileUploadFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }

    public function uploadNested(NestedFileUploadFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

it('emits an application/json request body $ref for a directly type-hinted Data class', function (): void {
    Route::post('/spatie-data/request', [DataClassRequestController::class, 'store']);

    $spec = generateSpec();

    $body = $spec['paths']['/spatie-data/request']['post']['requestBody'] ?? null;

    expect($body)->not->toBeNull()
        ->and($body['required'])->toBeTrue()
        ->and($body['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/ScalarOnlyData');
});

it('registers the Data class as a component schema', function (): void {
    Route::post('/spatie-data/request', [DataClassRequestController::class, 'store']);

    $spec = generateSpec();

    expect($spec['components']['schemas'])->toHaveKey('ScalarOnlyData');
});

// region File uploads — multipart/form-data body

function spatieSchemaNameFromRef(string $ref): string
{
    return ltrim(str_replace('#/components/schemas/', '', $ref), '/');
}

it('emits a multipart/form-data body when a Data class carries an UploadedFile field', function (): void {
    Route::post('/spatie-data/upload', [DataClassRequestController::class, 'upload']);

    $spec = generateSpec();

    $body = $spec['paths']['/spatie-data/upload']['post']['requestBody'] ?? null;

    expect($body)->not->toBeNull()
        ->and($body['content'])->toHaveKey('multipart/form-data')
        ->and($body['content'])->not->toHaveKey('application/json');
});

it('emits the file field as type=string format=binary while scalars keep their type', function (): void {
    Route::post('/spatie-data/upload', [DataClassRequestController::class, 'upload']);

    $spec = generateSpec();

    $body       = $spec['paths']['/spatie-data/upload']['post']['requestBody'];
    $schemaName = spatieSchemaNameFromRef($body['content']['multipart/form-data']['schema']['$ref']);
    $props      = $spec['components']['schemas'][$schemaName]['properties'];

    expect($props['file']['type'])->toBe('string')
        ->and($props['file']['format'])->toBe('binary')
        ->and($props['name']['type'])->toBe('string')
        ->and($props['name'])->not->toHaveKey('format');
});

it('emits multipart/form-data when the file lives in a nested Data class', function (): void {
    Route::post('/spatie-data/upload-nested', [DataClassRequestController::class, 'uploadNested']);

    $spec = generateSpec();

    $body = $spec['paths']['/spatie-data/upload-nested']['post']['requestBody'] ?? null;

    expect($body)->not->toBeNull()
        ->and($body['content'])->toHaveKey('multipart/form-data')
        ->and($body['content'])->not->toHaveKey('application/json');
});

it('keeps the body as application/json for a file-free Data class', function (): void {
    Route::post('/spatie-data/request', [DataClassRequestController::class, 'store']);

    $spec = generateSpec();

    $body = $spec['paths']['/spatie-data/request']['post']['requestBody'];

    expect($body['content'])->toHaveKey('application/json')
        ->and($body['content'])->not->toHaveKey('multipart/form-data');
});

// endregion
