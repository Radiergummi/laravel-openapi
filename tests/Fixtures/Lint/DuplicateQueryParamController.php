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
use Radiergummi\OpenApi\Core\Lint\Rules\QueryParamDuplicate;

/**
 * Fixture controller for testing the {@see QueryParamDuplicate} rule.
 */
final class DuplicateQueryParamController
{
    #[\Radiergummi\OpenApi\Core\Attributes\QueryParam('q', description: 'Search query')]
    #[\Radiergummi\OpenApi\Core\Attributes\QueryParam('q', description: 'Duplicate search query')]
    #[\Radiergummi\OpenApi\Core\Attributes\QueryParam('limit', type: 'integer')]
    public function withDuplicates(): JsonResponse
    {
        return response()->json();
    }

    #[\Radiergummi\OpenApi\Core\Attributes\QueryParam('q', description: 'Search query')]
    #[\Radiergummi\OpenApi\Core\Attributes\QueryParam('limit', type: 'integer')]
    #[\Radiergummi\OpenApi\Core\Attributes\QueryParam('offset', type: 'integer')]
    public function withoutDuplicates(): JsonResponse
    {
        return response()->json();
    }

    public function withoutQueryParams(): JsonResponse
    {
        return response()->json();
    }
}
