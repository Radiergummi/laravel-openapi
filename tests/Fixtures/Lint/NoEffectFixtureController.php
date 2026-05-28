<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Lint\Rules\FieldNoEffect;

/**
 * Fixture controller for testing {@see FieldNoEffect}.
 */
final class NoEffectFixtureController
{
    public function withNoEffect(NoEffectFixtureData $data): JsonResponse
    {
        return response()->json();
    }

    public function withoutData(): JsonResponse
    {
        return response()->json();
    }
}
