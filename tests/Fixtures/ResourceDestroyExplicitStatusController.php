<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * A resourceful `destroy` returning `response()->json([...], 202)`: an explicit status wins over both
 * the convention and the helper-default 200.
 */
class ResourceDestroyExplicitStatusController extends Controller
{
    public function destroy(string $widget): JsonResponse
    {
        return response()->json(['message' => 'Accepted.'], 202);
    }
}
