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
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Lint\Rules\DeprecatedAttribute;

/**
 * Fixture controller for testing the {@see DeprecatedAttribute} rule.
 */
final class DeprecatedAttrController
{
    #[DeprecatedTestAttribute]
    public function withDeprecatedAttribute(): JsonResponse
    {
        return response()->json();
    }

    #[Response(status: 200, description: 'OK')]
    public function withNonDeprecatedAttribute(): JsonResponse
    {
        return response()->json();
    }

    public function withoutAttributes(): JsonResponse
    {
        return response()->json();
    }
}
