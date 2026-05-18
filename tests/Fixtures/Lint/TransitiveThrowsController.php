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
use RuntimeException;

/**
 * Fixture controller for testing the {@see \Radiergummi\OpenApi\Core\Lint\Rules\ThrowsTransitiveMissing} rule.
 *
 * - `missingThrows()`: type-hints FakeAction but does NOT declare @throws RuntimeException.
 * - `withThrows()`: type-hints FakeAction and DOES declare @throws RuntimeException.
 * - `noAction()`: does not type-hint any Action class.
 */
final class TransitiveThrowsController
{
    public function missingThrows(FakeAction $action): JsonResponse
    {
        return response()->json();
    }

    /**
     * @throws RuntimeException
     */
    public function withThrows(FakeAction $action): JsonResponse
    {
        return response()->json();
    }

    public function noAction(): JsonResponse
    {
        return response()->json();
    }
}
