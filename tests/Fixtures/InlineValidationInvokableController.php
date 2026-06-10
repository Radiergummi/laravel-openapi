<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pins the component key for invokable controllers: no `__invoke` method segment.
 */
class InlineValidationInvokableController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host' => 'required|string',
        ]);

        return new JsonResponse($validated, 201);
    }
}
