<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fractal;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\ArticleEntityController;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\EmptyTransformerEntityController;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\NoDefaultEntityController;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\EmptyTransformer;

use function array_any;
use function str_contains;

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

it('documents the property default when the reassignment hides in a called helper — invisible to the bounded scan', function (): void {
    Route::get('/entities/{entity}/helper-reassigned', [ArticleEntityController::class, 'helperReassigned']);

    $spec = generateSpec();
    $schema = entityResponseSchema($spec, '/entities/{entity}/helper-reassigned');

    // Deliberate Tier-1 boundary: only the scanned method's statements are visible, so a
    // reassignment inside a called helper cannot be seen and the class default is documented.
    expect($schema['properties']['data']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('refuses a transformer whose readable transform() literal is empty, noting it yields no documentable fields', function (): void {
    Route::get('/entities/{entity}/empty', [EmptyTransformerEntityController::class, 'show']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $schema = entityResponseSchema($spec, '/entities/{entity}/empty');

    expect($schema['properties']['data']['$ref'] ?? null)->toBeNull();

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], EmptyTransformer::class)
            && str_contains($record['message'], 'yields no documentable fields'),
    );

    expect($noted)->toBeTrue();
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
