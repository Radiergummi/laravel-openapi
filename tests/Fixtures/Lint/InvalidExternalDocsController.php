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
 * Fixture controller for testing the {@see \Radiergummi\OpenApi\Core\Lint\Rules\ExternaldocsInvalidUrl} rule.
 */
final class InvalidExternalDocsController
{
    #[\Radiergummi\OpenApi\Core\Attributes\ExternalDocs(url: 'https://docs.matchory.com/search')]
    public function withValidUrl(): JsonResponse
    {
        return response()->json();
    }

    #[\Radiergummi\OpenApi\Core\Attributes\ExternalDocs(url: 'not-a-url')]
    public function withInvalidUrl(): JsonResponse
    {
        return response()->json();
    }

    #[\Radiergummi\OpenApi\Core\Attributes\ExternalDocs(url: '')]
    public function withEmptyUrl(): JsonResponse
    {
        return response()->json();
    }

    public function withoutExternalDocs(): JsonResponse
    {
        return response()->json();
    }
}
