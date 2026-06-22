<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Tests\Fixtures\Models\User;

uses()->group('openapi');

// region Helpers

/**
 * @param array<string, mixed> $spec
 *
 * @return array<string, array<string, mixed>> parameters of the operation in the given `in`, keyed by name
 */
function parametersIn(array $spec, string $path, string $verb, string $in): array
{
    $parameters = [];

    foreach ($spec['paths'][$path][$verb]['parameters'] ?? [] as $parameter) {
        if ($parameter['in'] === $in) {
            $parameters[$parameter['name']] = $parameter;
        }
    }

    return $parameters;
}

// endregion

// region Fixture controller — one action per scenario so routes don't bleed

class ParamDocblockController extends Controller
{
    /**
     * @param string $slug The post slug.
     */
    public function pathDescribed(string $slug): JsonResponse
    {
        return new JsonResponse([$slug]);
    }

    /**
     * @param string $slug The docblock slug.
     */
    public function pathAttributeWins(
        #[PathParam(description: 'The attribute slug.')]
        string $slug,
    ): JsonResponse {
        return new JsonResponse([$slug]);
    }

    public function pathSynthetic(User $user): JsonResponse
    {
        return new JsonResponse([$user]);
    }

    /**
     * @param User $user The author.
     */
    public function pathDescribedOverBinding(User $user): JsonResponse
    {
        return new JsonResponse([$user]);
    }

    /**
     * @param string $slug The optional slug.
     */
    public function pathOptional(?string $slug = null): JsonResponse
    {
        return new JsonResponse([$slug]);
    }

    public function pathUndescribed(string $slug): JsonResponse
    {
        return new JsonResponse([$slug]);
    }
}

// endregion

// region Path parameters

it('uses the @param description as a path parameter description', function (): void {
    Route::get('/param-doc/posts/{slug}', [ParamDocblockController::class, 'pathDescribed']);

    $params = parametersIn(generateSpec(), '/param-doc/posts/{slug}', 'get', 'path');

    expect($params['slug']['description'])->toBe('The post slug.');
});

it('lets #[PathParam] description win over the @param description', function (): void {
    Route::get('/param-doc/attr/{slug}', [ParamDocblockController::class, 'pathAttributeWins']);

    $params = parametersIn(generateSpec(), '/param-doc/attr/{slug}', 'get', 'path');

    expect($params['slug']['description'])->toBe('The attribute slug.');
});

it('keeps the synthetic model-binding description when there is no @param', function (): void {
    Route::get('/param-doc/synthetic/{user}', [ParamDocblockController::class, 'pathSynthetic']);

    $params = parametersIn(generateSpec(), '/param-doc/synthetic/{user}', 'get', 'path');

    expect($params['user']['description'])->toContain('Bound by');
});

it('lets the @param description win over the synthetic model-binding description', function (): void {
    Route::get('/param-doc/over-binding/{user}', [ParamDocblockController::class, 'pathDescribedOverBinding']);

    $params = parametersIn(generateSpec(), '/param-doc/over-binding/{user}', 'get', 'path');

    expect($params['user']['description'])->toBe('The author.');
});

it('appends the optional-segment note after the @param description', function (): void {
    Route::get('/param-doc/optional/{slug?}', [ParamDocblockController::class, 'pathOptional']);

    $params = parametersIn(generateSpec(), '/param-doc/optional/{slug?}', 'get', 'path');

    expect($params['slug']['description'])->toBe(
        'The optional slug. Optional in URL — the segment may be omitted when calling this route.',
    );
});

it('emits no path parameter description when there is no @param or synthetic source', function (): void {
    Route::get('/param-doc/plain/{slug}', [ParamDocblockController::class, 'pathUndescribed']);

    $params = parametersIn(generateSpec(), '/param-doc/plain/{slug}', 'get', 'path');

    expect($params['slug'])->not->toHaveKey('description');
});

// endregion

// Query-parameter @param fallback (the OperationBuilder fill + its precedence against resolver
// descriptions) is covered in tests/Unit/Support/Generator/OperationBuilderTest.php: an action's
// `@param` names a query key, not a PHP signature parameter, so a docblock fixture documenting one
// is stripped by `no_superfluous_phpdoc_tags` — the fallback is exercised with an explicit
// paramDescriptions map instead.
