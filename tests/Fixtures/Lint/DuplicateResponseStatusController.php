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
 * Fixture controller for testing the {@see \Radiergummi\OpenApi\Core\Lint\Rules\ResponseDuplicateStatus} rule.
 */
final class DuplicateResponseStatusController
{
    #[\Radiergummi\OpenApi\Core\Attributes\Response(status: 200, description: 'First 200')]
    #[\Radiergummi\OpenApi\Core\Attributes\Response(status: 200, description: 'Second 200')]
    #[\Radiergummi\OpenApi\Core\Attributes\Response(status: 404, description: 'Not found')]
    public function withDuplicates(): JsonResponse
    {
        return response()->json();
    }

    #[\Radiergummi\OpenApi\Core\Attributes\Response(status: 200, description: 'OK')]
    #[\Radiergummi\OpenApi\Core\Attributes\Response(status: 404, description: 'Not found')]
    #[\Radiergummi\OpenApi\Core\Attributes\Response(status: 422, description: 'Validation failed')]
    public function withoutDuplicates(): JsonResponse
    {
        return response()->json();
    }

    public function withoutResponses(): JsonResponse
    {
        return response()->json();
    }
}
