<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The modern Laravel 11+ idiom: static `HasMiddleware::middleware()`. Resolved natively by
 * `Route::gatherMiddleware()` without instantiation — pinned by a feature test, no scanner
 * involvement.
 */
class StaticMiddlewareFixtureController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('auth:sanctum', only: ['index'])];
    }

    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function show(string $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }
}
