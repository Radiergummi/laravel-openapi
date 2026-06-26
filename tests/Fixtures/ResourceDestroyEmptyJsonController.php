<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * A resourceful `destroy` returning `response()->json([])`: an empty literal carries no content, so
 * the conventional 204 stands. Guards that the suppression keys on actual content, not the call.
 */
class ResourceDestroyEmptyJsonController extends Controller
{
    public function destroy(string $widget): JsonResponse
    {
        return response()->json([]);
    }
}
