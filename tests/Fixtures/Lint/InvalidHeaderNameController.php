<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\Header;
use Radiergummi\OpenApi\Lint\Rules\HeaderInvalidName;

/**
 * Fixture controller for testing the {@see HeaderInvalidName} rule.
 */
#[Header('X-Class-Valid')]
final class InvalidHeaderNameController
{
    #[Header('X-Request-Id', description: 'Valid header')]
    #[Header('Content-Type')]
    #[Header('2FA-Token', description: 'Digit-leading tokens are valid per RFC 7230')]
    public function withValidHeaders(): JsonResponse
    {
        return response()->json();
    }

    #[Header('Invalid Header Name', description: 'Spaces are not allowed')]
    public function withInvalidHeaderSpace(): JsonResponse
    {
        return response()->json();
    }

    #[Header('', description: 'Empty name')]
    public function withEmptyHeaderName(): JsonResponse
    {
        return response()->json();
    }

    #[Header('Valid-Name')]
    #[Header('also/invalid', description: 'Slash is not a token char')]
    public function withMixedHeaders(): JsonResponse
    {
        return response()->json();
    }

    public function withoutHeaders(): JsonResponse
    {
        return response()->json();
    }
}
