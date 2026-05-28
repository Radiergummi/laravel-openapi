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
use Radiergummi\OpenApi\Attributes\Tag;
use Radiergummi\OpenApi\Lint\Rules\TagDuplicate;

/**
 * Fixture controller for testing the {@see TagDuplicate} rule.
 */
final class DuplicateTagController
{
    #[Tag('Search')]
    #[Tag('Search')]
    #[Tag('Beta')]
    public function withDuplicateTags(): JsonResponse
    {
        return response()->json();
    }

    #[Tag('Search')]
    #[Tag('Beta')]
    #[Tag('Experimental')]
    public function withUniqueTags(): JsonResponse
    {
        return response()->json();
    }

    public function withoutTags(): JsonResponse
    {
        return response()->json();
    }
}
