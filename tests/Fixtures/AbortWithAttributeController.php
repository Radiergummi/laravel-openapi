<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Response;

class AbortWithAttributeController extends Controller
{
    #[Response(status: 403, description: 'You shall not pass')]
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->boolean('allowed'), 403, 'Inferred description that must lose');

        return new JsonResponse([]);
    }
}
