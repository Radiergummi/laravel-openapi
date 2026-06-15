<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fractal;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\FacadeFractalController;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\HelperFractalController;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\ManagerFractalController;
use Radiergummi\OpenApi\Tests\Fixtures\Fractal\NonFractalReceiverController;
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
function callShapeSchema(array $spec, string $path, string $mediaType = 'application/json'): ?array
{
    return $spec['paths'][$path]['get']['responses']['200']['content'][$mediaType]['schema'] ?? null;
}

it('binds fractal()->item() to the single envelope', function (): void {
    Route::get('/helper/item', [HelperFractalController::class, 'item']);

    $schema = callShapeSchema(generateSpec(), '/helper/item');

    expect($schema['properties']['data']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('binds fractal()->collection() to the collection envelope', function (): void {
    Route::get('/helper/collection', [HelperFractalController::class, 'collection']);

    $schema = callShapeSchema(generateSpec(), '/helper/collection');

    expect($schema['properties']['data']['type'] ?? null)->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('reads the transformer from a ::class argument', function (): void {
    Route::get('/helper/class-const', [HelperFractalController::class, 'classConstTransformer']);

    $schema = callShapeSchema(generateSpec(), '/helper/class-const');

    expect($schema['properties']['data']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('binds the Fractalistic facade item() to the single envelope', function (): void {
    Route::get('/facade/item', [FacadeFractalController::class, 'item']);

    $schema = callShapeSchema(generateSpec(), '/facade/item');

    expect($schema['properties']['data']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('binds the Fractalistic facade collection() to the collection envelope', function (): void {
    Route::get('/facade/collection', [FacadeFractalController::class, 'collection']);

    $schema = callShapeSchema(generateSpec(), '/facade/collection');

    expect($schema['properties']['data']['type'] ?? null)->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('binds an injected Manager + new Item() to the single envelope', function (): void {
    Route::get('/manager/item', [ManagerFractalController::class, 'item']);

    $schema = callShapeSchema(generateSpec(), '/manager/item');

    expect($schema['properties']['data']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('binds an injected Manager + new Collection() to the collection envelope', function (): void {
    Route::get('/manager/collection', [ManagerFractalController::class, 'collection']);

    $schema = callShapeSchema(generateSpec(), '/manager/collection');

    expect($schema['properties']['data']['type'] ?? null)->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('applies the ArraySerializer envelope when serializeWith names it', function (): void {
    Route::get('/helper/array-serializer', [HelperFractalController::class, 'arraySerializer']);

    $schema = callShapeSchema(generateSpec(), '/helper/array-serializer');

    // ArraySerializer collections are a top-level array, not a {data: [...]} wrapper.
    expect($schema['type'] ?? null)->toBe('array')
        ->and($schema['items']['$ref'] ?? null)->toBe('#/components/schemas/InferredArticleTransformer');
});

it('applies the JsonApi envelope and media type when serializeWith names it', function (): void {
    Route::get('/helper/jsonapi', [HelperFractalController::class, 'jsonApiSerializer']);

    $schema = callShapeSchema(generateSpec(), '/helper/jsonapi', 'application/vnd.api+json');

    expect($schema['properties']['data']['properties']['attributes']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('refuses the bare two-argument helper form — item vs collection is not knowable', function (): void {
    Route::get('/helper/bare', [HelperFractalController::class, 'bareTwoArgument']);

    $schema = callShapeSchema(generateSpec(), '/helper/bare');

    expect($schema['properties']['data'] ?? null)->toBeNull();
});

it('refuses a variable transformer argument', function (): void {
    Route::get('/helper/variable', [HelperFractalController::class, 'variableTransformer']);

    $schema = callShapeSchema(generateSpec(), '/helper/variable');

    expect($schema['properties']['data'] ?? null)->toBeNull();
});

it('refuses a transformer with no documentable fields, noting it', function (): void {
    Route::get('/helper/empty', [HelperFractalController::class, 'emptyTransformer']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $schema = callShapeSchema(generateSpec(), '/helper/empty');

    expect($schema['properties']['data'] ?? null)->toBeNull();

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], EmptyTransformer::class)
            && str_contains($record['message'], 'yields no documentable fields'),
    );

    expect($noted)->toBeTrue();
});

it('refuses an unrecognised serializer rather than guessing the envelope', function (): void {
    Route::get('/helper/unknown-serializer', [HelperFractalController::class, 'unknownSerializer']);

    $schema = callShapeSchema(generateSpec(), '/helper/unknown-serializer');

    expect($schema['properties']['data'] ?? null)->toBeNull();
});

it('does not fire on item()/collection() calls on a non-Fractal receiver', function (): void {
    Route::get('/non-fractal/service', [NonFractalReceiverController::class, 'viaService']);
    Route::get('/non-fractal/query', [NonFractalReceiverController::class, 'viaQuery']);

    $spec = generateSpec();

    expect(callShapeSchema($spec, '/non-fractal/service')['properties']['data'] ?? null)->toBeNull()
        ->and(callShapeSchema($spec, '/non-fractal/query')['properties']['data'] ?? null)->toBeNull()
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('InferredArticleTransformer');
});
