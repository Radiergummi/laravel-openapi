<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * A resourceful `store` returning `response()->json([])`: an empty literal carries no content, but a
 * non-204 convention (201) relabels regardless. Proves the suppression is scoped to 204 only.
 */
class ResourceStoreEmptyJsonController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json([]);
    }
}
