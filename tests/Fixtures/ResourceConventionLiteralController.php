<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Resourceful actions whose body returns a `response()->json([...], <status>)` with an explicit
 * literal 2xx status that conflicts with the resource convention (#240). The unqualified
 * `response()` helper is the realistic idiom the body scan resolves.
 */
class ResourceConventionLiteralController extends Controller
{
    // POST store → convention says 201, but the author wrote 200; the literal must win.
    public function store(): JsonResponse
    {
        return response()->json(['id' => 1], 200);
    }

    // POST store → convention says 201, but the chained ->setStatusCode(200) must win.
    public function storeSetStatusCode(): JsonResponse
    {
        return response()->json(['id' => 1])->setStatusCode(200);
    }

    // GET index → convention says 200, but the author wrote 201; the literal must win.
    public function index(): JsonResponse
    {
        return response()->json(['items' => []], 201);
    }

    // POST store → convention says 201, but ->noContent() must win with 204.
    public function storeNoContent(): SymfonyResponse
    {
        return response()->noContent();
    }
}
