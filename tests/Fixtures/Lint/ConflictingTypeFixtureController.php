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
use Radiergummi\OpenApi\Lint\Rules\FieldConflictingType;

/**
 * Fixture controller for testing {@see FieldConflictingType}.
 */
final class ConflictingTypeFixtureController
{
    public function withConflict(ConflictingTypeFixtureData $data): JsonResponse
    {
        return response()->json();
    }

    public function withoutData(): JsonResponse
    {
        return response()->json();
    }
}
