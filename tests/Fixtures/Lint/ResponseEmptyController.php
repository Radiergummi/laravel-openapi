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
use Radiergummi\OpenApi\Core\Attributes\IgnoreLint;

/**
 * Fixture controller that triggers {@code response.success-empty-body} at level 2 and nothing
 * else within that threshold.
 *
 * It suppresses {@code response.no-error} and {@code response.resource.indeterminate} so that
 * {@code response.success-empty-body} is the sole finding at levels 0–2, making command-level
 * tests for suppression and severity overrides deterministic.
 */
final class ResponseEmptyController
{
    /**
     * Return an undocumented JSON payload.
     *
     * No ApiResource return type is declared, so the response-schema extractor cannot derive a
     * schema — triggering response.success-empty-body.
     */
    #[IgnoreLint('response.no-error', reason: 'fixture: not testing error responses')]
    #[IgnoreLint('response.resource.indeterminate', reason: 'fixture: intentionally missing resource')]
    public function index(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
