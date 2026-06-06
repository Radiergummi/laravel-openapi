<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

uses()->group('openapi');

// Inline fixture controller: a route-model-bound action carrying no @throws, so the only signal
// for the 404 is the implicit binding itself.
class Issue29BoundModelController
{
    public function show(Article $article): array
    {
        return ['id' => $article->getKey()];
    }
}

it('emits a 404 from an implicit route-model binding, with the configured envelope body', function (): void {
    config()->set('openapi.error_envelope', 'laravel');

    Route::get('/articles/{article}', [Issue29BoundModelController::class, 'show'])
        ->name('articles.show');

    $spec = generateSpec();

    $operation = $spec['paths']['/articles/{article}']['get'];

    expect($operation['responses'])->toHaveKey('404');

    // Body matches the standard error envelope — inlined per operation, referencing the shared
    // Error schema component.
    $notFound = $operation['responses']['404'];
    expect($notFound)->toHaveKey('content');
    expect($notFound['content'])->toHaveKey('application/json');
    expect($notFound['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/Error');
});

it('emits a 403 from a can: authorization middleware', function (): void {
    Route::middleware('can:update,article')
        ->get('/articles/{article}/edit', [Issue29BoundModelController::class, 'show'])
        ->name('articles.edit');

    $spec = generateSpec();

    expect($spec['paths']['/articles/{article}/edit']['get']['responses'])->toHaveKey('403');
});
