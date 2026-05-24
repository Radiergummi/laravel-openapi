<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Core\Attributes\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fixture controller that is fully documented and produces zero lint findings at level 0.
 *
 * Uses a {@see StreamedResponse} return type so the response-schema extractor detects streaming
 * automatically (no {@code response.empty}), and the description-only {@see Response} override
 * replaces the auto-derived streaming response with a schema-free 200 — avoiding
 * {@code spec.invalid} false positives from the OpenAPI 3.1 meta-schema validator.
 */
final class CleanController
{
    /**
     * List items as a server-sent event stream.
     *
     * Returns a continuous SSE stream of item events.
     */
    #[Response(status: 200, description: 'Item list')]
    public function list(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "data: []\n\n";
        });
    }
}
