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
 * Fixture identical to {@see ResponseEmptyController} but with an additional
 * {@see IgnoreLint} that silences {@code response.empty}.
 *
 * Used by command-level tests that verify suppression directives cause exit 0.
 */
final class SuppressedResponseEmptyController
{
    /**
     * Return an undocumented JSON payload.
     *
     * No ApiResource return type is declared, so the response-schema extractor
     * cannot derive a schema — triggering response.empty.
     */
    #[IgnoreLint('response.empty', reason: 'fixture: testing suppression mechanics')]
    #[IgnoreLint('response.no-error', reason: 'fixture: not testing error responses')]
    #[IgnoreLint('response.resource.indeterminate', reason: 'fixture: intentionally missing resource')]
    public function index(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
