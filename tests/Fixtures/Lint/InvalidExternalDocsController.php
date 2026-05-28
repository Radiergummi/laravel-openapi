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
use Radiergummi\OpenApi\Attributes\ExternalDocs;
use Radiergummi\OpenApi\Lint\Rules\ExternaldocsInvalidUrl;

/**
 * Fixture controller for testing the {@see ExternaldocsInvalidUrl} rule.
 */
final class InvalidExternalDocsController
{
    #[ExternalDocs(url: 'https://docs.matchory.com/search')]
    public function withValidUrl(): JsonResponse
    {
        return response()->json();
    }

    #[ExternalDocs(url: 'not-a-url')]
    public function withInvalidUrl(): JsonResponse
    {
        return response()->json();
    }

    #[ExternalDocs(url: '')]
    public function withEmptyUrl(): JsonResponse
    {
        return response()->json();
    }

    public function withoutExternalDocs(): JsonResponse
    {
        return response()->json();
    }
}
