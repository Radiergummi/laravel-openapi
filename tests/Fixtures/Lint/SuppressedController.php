<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\IgnoreLint;

/**
 * Fixture controller identical to {@see BrokenController} but with an
 * {@see IgnoreLint} attribute that silences {@code response.empty}.
 */
final class SuppressedController
{
    /**
     * Stream events as a server-sent event stream.
     *
     * Returns a continuous SSE stream of ping events.
     */
    #[IgnoreLint('response.empty', reason: 'SSE endpoint')]
    public function stream(): JsonResponse
    {
        return response()->json(['event' => 'ping']);
    }
}
