<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined\Http;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\Hide;

/**
 * Demonstrates `#[Hide]` — the route below is registered with Laravel at
 * runtime, but {@see Hide} causes the
 * generator to omit it from the OpenAPI document.
 */
final class InternalController
{
    #[Hide]
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
