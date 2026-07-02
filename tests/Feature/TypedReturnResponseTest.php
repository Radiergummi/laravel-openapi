<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;

uses()->group('openapi');

class TypedReturnController extends Controller
{
    /**
     * @return array{id: int, name: string}
     */
    public function arrayShape(): array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function scalar(): string
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function backedEnum(): ArticleStatus
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return array<string, int>
     */
    public function map(): array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return list<int>
     */
    public function listOfScalars(): array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return array{id: int}
     */
    public function nullableShape(): ?array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function scalarUnion(): int|string
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function untyped()
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    // A Spatie Data return must stay claimed by the SpatieData plugin, not the baseline.
    public function dataReturn(): ScalarOnlyData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

function typedReturnSchema(string $uri): ?array
{
    return generateSpec()['paths'][$uri]['get']['responses']['200']['content']['application/json']['schema'] ?? null;
}

it('documents a documented array-shape return as an object schema', function (): void {
    Route::get('/typed/array-shape', [TypedReturnController::class, 'arrayShape']);

    $schema = typedReturnSchema('/typed/array-shape');

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['id', 'name'])
        ->and($schema['properties']['id']['type'])->toBe('integer')
        ->and($schema['properties']['name']['type'])->toBe('string')
        ->and($schema['required'])->toEqualCanonicalizing(['id', 'name']);
});

it('documents a scalar return', function (): void {
    Route::get('/typed/scalar', [TypedReturnController::class, 'scalar']);

    expect(typedReturnSchema('/typed/scalar'))->toBe(['type' => 'string']);
});

it('documents a backed-enum return as a component $ref', function (): void {
    Route::get('/typed/enum', [TypedReturnController::class, 'backedEnum']);

    expect(typedReturnSchema('/typed/enum')['$ref'] ?? null)
        ->toBe('#/components/schemas/ArticleStatus');
});

it('documents a string-keyed map return as additionalProperties', function (): void {
    Route::get('/typed/map', [TypedReturnController::class, 'map']);

    $schema = typedReturnSchema('/typed/map');

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties'])->toBe(['type' => 'integer']);
});

it('documents a list return as an array schema', function (): void {
    Route::get('/typed/list', [TypedReturnController::class, 'listOfScalars']);

    $schema = typedReturnSchema('/typed/list');

    expect($schema['type'])->toBe('array')
        ->and($schema['items'])->toBe(['type' => 'integer']);
});

it('wraps a nullable typed return in the OAS 3.1 nullable idiom', function (): void {
    Route::get('/typed/nullable', [TypedReturnController::class, 'nullableShape']);

    $schema = typedReturnSchema('/typed/nullable');

    expect($schema['oneOf'] ?? null)->toHaveCount(2)
        ->and($schema['oneOf'][1])->toBe(['type' => 'null'])
        ->and($schema['oneOf'][0]['type'])->toBe('object');
});

it('documents a scalar union as oneOf', function (): void {
    Route::get('/typed/union', [TypedReturnController::class, 'scalarUnion']);

    $schema = typedReturnSchema('/typed/union');

    expect($schema['oneOf'] ?? null)->toBe([
        ['type' => 'integer'],
        ['type' => 'string'],
    ]);
});

it('degrades an untyped return to no response body', function (): void {
    Route::get('/typed/untyped', [TypedReturnController::class, 'untyped']);

    $response = generateSpec()['paths']['/typed/untyped']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});

it('leaves a Spatie Data return to the SpatieData plugin, not the baseline', function (): void {
    Route::get('/typed/data', [TypedReturnController::class, 'dataReturn']);

    expect(typedReturnSchema('/typed/data')['$ref'] ?? null)
        ->toBe('#/components/schemas/ScalarOnlyData');
});
