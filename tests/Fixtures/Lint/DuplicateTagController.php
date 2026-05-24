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
use Radiergummi\OpenApi\Core\Lint\Rules\TagDuplicate;

/**
 * Fixture controller for testing the {@see TagDuplicate} rule.
 */
final class DuplicateTagController
{
    #[\Radiergummi\OpenApi\Core\Attributes\Tag('Search')]
    #[\Radiergummi\OpenApi\Core\Attributes\Tag('Search')]
    #[\Radiergummi\OpenApi\Core\Attributes\Tag('Beta')]
    public function withDuplicateTags(): JsonResponse
    {
        return response()->json();
    }

    #[\Radiergummi\OpenApi\Core\Attributes\Tag('Search')]
    #[\Radiergummi\OpenApi\Core\Attributes\Tag('Beta')]
    #[\Radiergummi\OpenApi\Core\Attributes\Tag('Experimental')]
    public function withUniqueTags(): JsonResponse
    {
        return response()->json();
    }

    public function withoutTags(): JsonResponse
    {
        return response()->json();
    }
}
