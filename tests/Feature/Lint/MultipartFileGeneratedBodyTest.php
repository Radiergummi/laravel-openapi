<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\FileUploadFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\FileUploadFixtureData;

uses()->group('openapi', 'lint', 'plugin:spatie-data');

class JsonOverrideFileUploadController
{
    #[RequestBody(mediaType: MediaType::Json)]
    public function upload(FileUploadFixtureData $data): void {}
}

beforeEach(function (): void {
    // Prevent Spatie Data from querying the (non-existent) cache DB table when
    // forgetScopedInstances() causes DataConfig to be re-resolved mid-test.
    config()->set('data.structure_caching.enabled', false);

    Route::post('lint-fixtures/multipart/upload', [FileUploadFixtureController::class, 'upload'])
        ->name('lint.multipart.upload');
    Route::post('lint-fixtures/multipart/json-override', [JsonOverrideFileUploadController::class, 'upload'])
        ->name('lint.multipart.json-override');
});

it('does not fire multipart.file-without-multipart for the auto-generated multipart body', function (): void {
    // The resolver emits multipart/form-data for a file-carrying Data class, so the contradiction
    // guard must stay silent on the generated path — it fires only on a non-multipart override.
    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'multipart.file-without-multipart',
        '--uri' => 'lint-fixtures/multipart/upload',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('fires multipart.file-without-multipart when a #[RequestBody] override forces application/json', function (): void {
    // Constructibility check: #[RequestBody(mediaType: Json)] rewrites the resolver-produced media
    // type in place, leaving the binary file field under application/json — the spec now
    // contradicts the code and the contradiction guard must fire.
    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'multipart.file-without-multipart',
        '--uri' => 'lint-fixtures/multipart/json-override',
        '--format' => 'json',
    ])->assertExitCode(1);
});
