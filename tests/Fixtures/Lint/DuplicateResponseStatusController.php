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
use Radiergummi\OpenApi\Lint\Rules\ResponseDuplicateStatus;

/**
 * Fixture controller for testing the {@see ResponseDuplicateStatus} rule.
 */
final class DuplicateResponseStatusController
{
    #[Response(status: 200, description: 'First 200')]
    #[Response(status: 200, description: 'Second 200')]
    #[Response(status: 404, description: 'Not found')]
    public function withDuplicates(): JsonResponse
    {
        return response()->json();
    }

    #[Response(status: 200, description: 'OK')]
    #[Response(status: 404, description: 'Not found')]
    #[Response(status: 422, description: 'Validation failed')]
    public function withoutDuplicates(): JsonResponse
    {
        return response()->json();
    }

    public function withoutResponses(): JsonResponse
    {
        return response()->json();
    }
}
