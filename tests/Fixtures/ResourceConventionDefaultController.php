<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * A resourceful `store` whose `response()->json([...])` carries no explicit status, so the body
 * scan defaults to 200 — the resource convention should still relabel it 201 (#240 control case).
 */
class ResourceConventionDefaultController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(['id' => 1]);
    }
}
