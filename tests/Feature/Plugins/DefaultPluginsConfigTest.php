<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;

use function array_map;
use function config;

uses()->group('openapi', 'plugin:suite');

#[TransformerField('id', type: 'integer')]
class DefaultsTransformer {}

class DefaultsController extends Controller
{
    /**
     * The QueryBuilder plugin would emit `filter[name]` if it were active; with the shipped
     * defaults it is not, so the operation must carry no `filter[…]` query parameter.
     */
    #[AllowedFilter('name', type: 'string')]
    public function withQueryBuilderHint(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /**
     * The Fractal plugin would emit a `{data}` envelope if it were active; with the shipped
     * defaults it is not, so the operation falls back to a bare 200.
     */
    #[FractalResponse(transformer: DefaultsTransformer::class)]
    public function withFractalHint(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

it('generates a clean document with the shipped default openapi.plugins array', function (): void {
    // Do NOT touch openapi.plugins — exercise whatever config/openapi.php ships.
    $shipped = require __DIR__ . '/../../../config/openapi.php';
    expect(config('openapi.plugins'))->toBe($shipped['plugins']);

    Route::get('/defaults/query-builder', [DefaultsController::class, 'withQueryBuilderHint']);
    Route::get('/defaults/fractal', [DefaultsController::class, 'withFractalHint']);

    $spec = generateSpec();

    // QueryBuilder plugin is disabled by default — no filter[*] parameters appear.
    $queryBuilderOp = $spec['paths']['/defaults/query-builder']['get'];
    $paramNames = array_map(
        static fn(array $p): string => $p['name'],
        $queryBuilderOp['parameters'] ?? [],
    );
    expect($paramNames)->not->toContain('filter[name]');

    // Fractal plugin is disabled by default — no transformer component schema, no data envelope.
    expect($spec['components']['schemas'] ?? [])->not->toHaveKey('DefaultsTransformer');

    $fractalSchema = $spec['paths']['/defaults/fractal']['get']['responses']['200']['content']['application/json']['schema'] ?? null;
    expect($fractalSchema)->toBeNull();
});
