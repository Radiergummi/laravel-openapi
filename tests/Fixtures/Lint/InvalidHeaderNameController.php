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
 * Fixture controller for testing the {@see \Radiergummi\OpenApi\Core\Lint\Rules\HeaderInvalidName} rule.
 */
#[\Radiergummi\OpenApi\Core\Attributes\Header('X-Class-Valid')]
final class InvalidHeaderNameController
{
    #[\Radiergummi\OpenApi\Core\Attributes\Header('X-Request-Id', description: 'Valid header')]
    #[\Radiergummi\OpenApi\Core\Attributes\Header('Content-Type')]
    #[\Radiergummi\OpenApi\Core\Attributes\Header('2FA-Token', description: 'Digit-leading tokens are valid per RFC 7230')]
    public function withValidHeaders(): JsonResponse
    {
        return response()->json();
    }

    #[\Radiergummi\OpenApi\Core\Attributes\Header('Invalid Header Name', description: 'Spaces are not allowed')]
    public function withInvalidHeaderSpace(): JsonResponse
    {
        return response()->json();
    }

    #[\Radiergummi\OpenApi\Core\Attributes\Header('', description: 'Empty name')]
    public function withEmptyHeaderName(): JsonResponse
    {
        return response()->json();
    }

    #[\Radiergummi\OpenApi\Core\Attributes\Header('Valid-Name')]
    #[\Radiergummi\OpenApi\Core\Attributes\Header('also/invalid', description: 'Slash is not a token char')]
    public function withMixedHeaders(): JsonResponse
    {
        return response()->json();
    }

    public function withoutHeaders(): JsonResponse
    {
        return response()->json();
    }
}
