<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\IgnoreLint;

/**
 * Fixture identical to {@see ResponseEmptyController} but with an additional {@see IgnoreLint}
 * that silences {@code response.success-empty-body}.
 *
 * Used by command-level tests that verify suppression directives cause exit 0.
 */
final class SuppressedResponseEmptyController
{
    /**
     * Return an undocumented JSON payload.
     *
     * No ApiResource return type is declared and the payload is a variable (which the Tier-1
     * body scan refuses), so no response schema can be derived — triggering
     * response.success-empty-body.
     */
    #[IgnoreLint('response.success-empty-body', reason: 'fixture: testing suppression mechanics')]
    #[IgnoreLint('response.no-error', reason: 'fixture: not testing error responses')]
    #[IgnoreLint('response.resource.indeterminate', reason: 'fixture: intentionally missing resource')]
    public function index(): JsonResponse
    {
        $payload = ['ok' => true];

        return response()->json($payload);
    }
}
