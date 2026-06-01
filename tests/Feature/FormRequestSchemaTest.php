<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\FileUploadFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\RemoteMediaFixtureController;

uses()->group('openapi');

// region Helpers

/**
 * Extracts the component schema name from a JSON Reference string such as
 * `#/components/schemas/RemoteMediaRequest`.
 */
function schemaNameFromRef(string $ref): string
{
    return basename(str_replace('#/components/schemas/', '', $ref));
}

// endregion

// region RemoteMediaRequest — application/json body

it('emits request body properties from RemoteMediaRequest rules', function (): void {
    Route::post('/oa-fixture/remote-media', [RemoteMediaFixtureController::class, 'store']);

    $spec = generateSpec();

    $body = $spec['paths']['/oa-fixture/remote-media']['post']['requestBody'] ?? null;
    expect($body)->not->toBeNull();

    $content = $body['content'];
    expect($content)->toHaveKey('application/json');

    $schemaName  = schemaNameFromRef($content['application/json']['schema']['$ref']);
    $schemaProps = $spec['components']['schemas'][$schemaName]['properties'] ?? [];

    expect($schemaProps)->toHaveKey('url')
        ->and($schemaProps)->toHaveKey('forwardErrors');
});

it('marks url as required in RemoteMediaRequest schema', function (): void {
    Route::post('/oa-fixture/remote-media', [RemoteMediaFixtureController::class, 'store']);

    $spec = generateSpec();

    $body       = $spec['paths']['/oa-fixture/remote-media']['post']['requestBody'];
    $schemaName = schemaNameFromRef($body['content']['application/json']['schema']['$ref']);
    $required   = $spec['components']['schemas'][$schemaName]['required'] ?? [];

    expect($required)->toContain('url')
        ->and($required)->not->toContain('forwardErrors');
});

it('applies maxLength:2048 to the url field from RemoteMediaRequest', function (): void {
    Route::post('/oa-fixture/remote-media', [RemoteMediaFixtureController::class, 'store']);

    $spec = generateSpec();

    $body       = $spec['paths']['/oa-fixture/remote-media']['post']['requestBody'];
    $schemaName = schemaNameFromRef($body['content']['application/json']['schema']['$ref']);
    $urlProp    = $spec['components']['schemas'][$schemaName]['properties']['url'];

    expect($urlProp['maxLength'])->toBe(2048)
        ->and($urlProp['type'])->toBe('string');
});

// endregion

// region FileUploadFormRequest — multipart/form-data body

it('emits multipart/form-data when FormRequest has a file rule', function (): void {
    Route::post('/oa-fixture/file-upload', [FileUploadFixtureController::class, 'upload']);

    $spec = generateSpec();

    $body = $spec['paths']['/oa-fixture/file-upload']['post']['requestBody'] ?? null;
    expect($body)->not->toBeNull();

    expect($body['content'])->toHaveKey('multipart/form-data');
});

it('emits file field as type=string format=binary in multipart schema', function (): void {
    Route::post('/oa-fixture/file-upload', [FileUploadFixtureController::class, 'upload']);

    $spec = generateSpec();

    $body       = $spec['paths']['/oa-fixture/file-upload']['post']['requestBody'];
    $schemaName = schemaNameFromRef($body['content']['multipart/form-data']['schema']['$ref']);
    $attachment = $spec['components']['schemas'][$schemaName]['properties']['attachment'];

    expect($attachment['type'])->toBe('string')
        ->and($attachment['format'])->toBe('binary');
});

// endregion

// region Runtime-state stubbing

it('emits a complete schema for a FormRequest whose rules() reads $this->route()', function (): void {
    Route::post(
        '/oa-fixture/route-bound/{contactInfoRequest}',
        [\Radiergummi\OpenApi\Tests\Fixtures\RouteBoundFixtureController::class, 'callback'],
    );

    $spec = generateSpec();

    $body = $spec['paths']['/oa-fixture/route-bound/{contactInfoRequest}']['post']['requestBody'] ?? null;
    expect($body)->not->toBeNull();

    $schemaName = schemaNameFromRef($body['content']['application/json']['schema']['$ref']);
    $schema = $spec['components']['schemas'][$schemaName];

    // The placeholder schema only has type+description and no `properties` key; a complete one
    // has the rules-derived properties.
    expect($schema)->toHaveKey('properties')
        ->and($schema['description'] ?? '')->not->toContain('Schema introspection failed');

    expect($schema['properties'])->toHaveKeys(['status', 'request_uuid', 'group_uuid', 'error'])
        ->and($schema['required'])->toContain('status', 'request_uuid', 'group_uuid');
});

// endregion
