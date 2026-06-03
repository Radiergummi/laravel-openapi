<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;

uses()->group('openapi', 'lint');

it('sanitises a namespaced named route into a lint-clean operationId', function (): void {
    Route::get('clients/{record}', static fn(): array => [])->name('api:client.show');

    $spec = generateSpec();

    $operationId = $spec['paths']['/clients/{record}']['get']['operationId'];

    // The `:` from the namespaced route name must not leak through; the `.` is permitted.
    expect($operationId)->toBe('api_client.show')
        ->and($operationId)->toMatch('/^[A-Za-z][A-Za-z0-9._-]*$/');
});

it('emits zero operation.id-invalid-chars findings for namespaced/bound routes', function (): void {
    Route::get('clients/{record}', static fn(): array => [])->name('api:client.show');

    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'operation.id-invalid-chars',
        '--format' => 'json',
    ])->assertExitCode(0);
});
