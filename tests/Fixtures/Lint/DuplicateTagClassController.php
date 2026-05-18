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
 * Fixture controller with class-level duplicate tags for testing
 * the {@see \Radiergummi\OpenApi\Core\Lint\Rules\TagDuplicate} rule.
 */
#[\Radiergummi\OpenApi\Core\Attributes\Tag('Admin')]
#[\Radiergummi\OpenApi\Core\Attributes\Tag('Admin')]
final class DuplicateTagClassController
{
    public function index(): JsonResponse
    {
        return response()->json();
    }
}
