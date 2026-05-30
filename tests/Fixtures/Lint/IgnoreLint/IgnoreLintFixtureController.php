<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint;

use Illuminate\Http\JsonResponse;

final class IgnoreLintFixtureController
{
    public function viaFormRequest(SnakeCasedFormRequest $request): JsonResponse
    {
        return response()->json();
    }

    public function viaJsonResource(): SnakeCasedJsonResource
    {
        return new SnakeCasedJsonResource(null);
    }
}
