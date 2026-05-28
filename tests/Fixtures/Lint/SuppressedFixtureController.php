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
use Radiergummi\OpenApi\Attributes\IgnoreLint;

/**
 * Fixture exercising every {@see IgnoreLint} scope for SuppressionCollector tests: class scope
 * on the controller, method scope on an action, and class/property scope reached transitively
 * through a Data-class parameter.
 */
#[IgnoreLint('tag.duplicate', reason: 'class scope')]
final class SuppressedFixtureController
{
    #[IgnoreLint('response.empty', reason: 'method scope')]
    public function methodWithSuppression(): JsonResponse
    {
        return response()->json();
    }

    public function methodWithoutSuppression(): JsonResponse
    {
        return response()->json();
    }

    public function methodWithDataParam(SuppressedFixtureData $data): JsonResponse
    {
        return response()->json($data->toArray());
    }
}
