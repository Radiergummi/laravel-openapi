<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Lint\Rules\FieldEnumMismatch;

/**
 * Fixture controller for testing {@see FieldEnumMismatch}.
 */
final class EnumMismatchFixtureController
{
    public function withMismatch(EnumMismatchFixtureData $data): JsonResponse
    {
        return response()->json();
    }

    public function withoutData(): JsonResponse
    {
        return response()->json();
    }
}
