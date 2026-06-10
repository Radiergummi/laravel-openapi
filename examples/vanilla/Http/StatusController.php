<?php

declare(strict_types=1);

namespace Examples\Vanilla\Http;

use Illuminate\Http\JsonResponse;

final class StatusController
{
    /**
     * Service status.
     *
     * Reports API liveness for uptime monitors. The response schema is inferred
     * from the literal `response()->json([...])` body — no attribute needed.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'operational',
            'read_only' => false,
            'incidents' => 0,
        ]);
    }
}
