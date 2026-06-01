<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Lint\Rules\QueryParamDuplicate;

/**
 * Fixture controller for testing the {@see QueryParamDuplicate} rule.
 */
final class DuplicateQueryParamController
{
    #[QueryParam('q', description: 'Search query')]
    #[QueryParam('q', description: 'Duplicate search query')]
    #[QueryParam('limit', type: 'integer')]
    public function withDuplicates(): JsonResponse
    {
        return response()->json();
    }

    #[QueryParam('q', description: 'Search query')]
    #[QueryParam('limit', type: 'integer')]
    #[QueryParam('offset', type: 'integer')]
    public function withoutDuplicates(): JsonResponse
    {
        return response()->json();
    }

    public function withoutQueryParams(): JsonResponse
    {
        return response()->json();
    }
}
