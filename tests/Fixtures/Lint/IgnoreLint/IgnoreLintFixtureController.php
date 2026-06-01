<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint;

use Illuminate\Http\JsonResponse;

final class IgnoreLintFixtureController
{
    public function viaFormRequest(SnakeCasedFormRequest $request): JsonResponse
    {
        return response()->json();
    }

    public function viaJsonResource(): SnakeCasedJsonResource
    {
        return new SnakeCasedJsonResource(null);
    }
}
