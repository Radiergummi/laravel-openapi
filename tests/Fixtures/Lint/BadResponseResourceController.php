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
use Radiergummi\OpenApi\Attributes\ResponseResource;

final class BadResponseResourceController
{
    /** @phpstan-ignore argument.type (intentionally invalid class-string for testing) */
    #[ResponseResource('Radiergummi\OpenApi\Tests\Fixtures\Lint\NotAnApiResource')]
    public function index(): JsonResponse
    {
        return response()->json();
    }
}
