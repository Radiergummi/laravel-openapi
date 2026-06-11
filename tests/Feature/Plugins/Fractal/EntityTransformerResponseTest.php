<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fractal;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\ArticleEntityController;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\NoDefaultEntityController;

uses()->group('openapi', 'plugin:fractal');

beforeEach(function (): void {
    config(['openapi.plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
        FractalPlugin::class,
    ]]);
});

/**
 * @param array<string, mixed> $spec
 *
 * @return null|array<string, mixed>
 */
function entityResponseSchema(array $spec, string $path): ?array
{
    return $spec['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'] ?? null;
}

it('binds itemResponse() to the single envelope of the $entity_transformer default', function (): void {
    Route::get('/entities/{entity}', [ArticleEntityController::class, 'show']);

    $spec = generateSpec();
    $schema = entityResponseSchema($spec, '/entities/{entity}');

    expect($schema)->not->toBeNull()
        ->and($schema['properties']['data']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('binds listResponse() to the collection envelope', function (): void {
    Route::get('/entities', [ArticleEntityController::class, 'index']);

    $spec = generateSpec();
    $schema = entityResponseSchema($spec, '/entities');

    expect($schema['properties']['data']['type'] ?? null)->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer')
        ->and($schema['properties'] ?? [])->not->toHaveKey('meta');
});

it('refuses a method that reassigns $entity_transformer', function (): void {
    Route::get('/entities/{entity}/reassigned', [ArticleEntityController::class, 'reassigned']);

    $spec = generateSpec();
    $schema = entityResponseSchema($spec, '/entities/{entity}/reassigned');

    expect($schema['properties']['data']['$ref'] ?? null)->toBeNull();
});

it('lets an explicit #[FractalResponse] win over the body scan', function (): void {
    Route::get('/entities/{entity}/attributed', [ArticleEntityController::class, 'attributed']);

    $spec = generateSpec();
    $schema = entityResponseSchema($spec, '/entities/{entity}/attributed');

    expect($schema['properties']['data']['$ref'] ?? null)
        ->toBe('#/components/schemas/DeclaredAndInferredTransformer');
});

it('stays silent for a method without the whitelisted call shape', function (): void {
    Route::get('/entities/plain', [ArticleEntityController::class, 'plain']);

    $spec = generateSpec();

    expect($spec['components']['schemas'] ?? [])->not->toHaveKey('InferredArticleTransformer');
});

it('degrades when $entity_transformer has no concrete default', function (): void {
    Route::get('/entities/{entity}/no-default', [NoDefaultEntityController::class, 'show']);

    $spec = generateSpec();
    $schema = entityResponseSchema($spec, '/entities/{entity}/no-default');

    expect($schema['properties']['data']['$ref'] ?? null)->toBeNull();
});
