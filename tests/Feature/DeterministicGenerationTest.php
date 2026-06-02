<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\Alpha\SelfRefData;

uses()->group('openapi', 'plugin:spatie-data');

class DeterminismFixtureController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }

    public function health(): JsonResponse
    {
        return new JsonResponse();
    }

    // Self-referential Data body: exercises the ComponentSchemaRegistry cycle guard, the most
    // likely source of run-to-run $ref drift.
    public function store(SelfRefData $node): JsonResponse
    {
        return new JsonResponse();
    }
}

beforeEach(function (): void {
    Route::get('/users', [DeterminismFixtureController::class, 'index'])->name('users.index');
    Route::get('/health/check', [DeterminismFixtureController::class, 'health']); // unnamed → URI-fallback id
    Route::post('/nodes', [DeterminismFixtureController::class, 'store']);
});

// Every operationId emitted across the document, in document order. File-scoped (not a global
// function) so the suite can't hit a redeclaration clash if another file picks the same name.
$collectOperationIds = static function (array $spec): array {
    $ids = [];

    foreach ($spec['paths'] ?? [] as $operations) {
        foreach ($operations as $operation) {
            if (is_array($operation) && isset($operation['operationId'])) {
                $ids[] = $operation['operationId'];
            }
        }
    }

    return $ids;
};

it('emits a unique operationId for every operation', function () use ($collectOperationIds): void {
    $ids = $collectOperationIds(generateSpec());

    expect($ids)->not->toBeEmpty()
        ->and(array_unique($ids))->toHaveCount(count($ids));
});

it('produces byte-identical output across two independent generation runs', function (): void {
    $render = static fn(): string => app(OpenApiGenerator::class)
        ->generate(app(SpecRegistry::class)->default(), app()->environment())
        ->toYaml();

    $first = $render();

    // Drop scoped pipeline state (ComponentSchemaRegistry, ExampleFileLoader) the way Octane resets
    // it between requests, so the second run is genuinely independent rather than reusing run 1's
    // registry — regeneration must still be byte-for-byte stable.
    app()->forgetScopedInstances();

    $second = $render();

    // Compare the rendered YAML strings, not parsed arrays: a structural (array) comparison ignores
    // map-key ordering, which is exactly the run-to-run drift (e.g. $ref ordering) this must catch.
    expect($second)->toBe($first);
});
