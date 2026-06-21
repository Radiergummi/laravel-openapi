<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\QueryParam;

/**
 * Query-parameter fixtures for the `@param` description fallback. The descriptions under test are
 * injected as an explicit `paramDescriptions` map by the unit test, not authored as `@param` tags:
 * an action's `@param` names a query key rather than a PHP signature parameter, so such a docblock
 * would be stripped by Pint's `no_superfluous_phpdoc_tags`.
 */
class ParamDocblockQueryController extends Controller
{
    public function accessorRead(Request $request): JsonResponse
    {
        return new JsonResponse([$request->query('sort')]);
    }

    #[QueryParam('sort', description: 'The attribute sort.', type: 'string')]
    public function attributeDescribed(Request $request): JsonResponse
    {
        return new JsonResponse([$request->query('sort')]);
    }

    public function validateCommented(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sort' => 'string', // The inline sort comment.
        ]);

        return new JsonResponse($validated);
    }
}
