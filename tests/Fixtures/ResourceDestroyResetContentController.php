<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * A resourceful `destroy` returning `response()->json([...], 205)`: the explicit status wins over the
 * conventional 204, and the body it writes cannot be represented under it.
 */
class ResourceDestroyResetContentController extends Controller
{
    public function destroy(string $widget): JsonResponse
    {
        return response()->json(['message' => 'Reset.'], 205);
    }
}
