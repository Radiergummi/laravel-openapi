<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;

/**
 * Fixture controller with no OpenAPI attributes — triggers {@code response.empty}
 * at level 0.
 */
final class BrokenController
{
    public function stream(): JsonResponse
    {
        return response()->json(['event' => 'ping']);
    }
}
