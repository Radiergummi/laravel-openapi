<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Response;

class InlineJsonWithAttributeController extends Controller
{
    #[Response(
        status: 200,
        description: 'Authored response that must win',
        schema: ['type' => 'object', 'properties' => ['authored' => ['type' => 'string']]],
    )]
    public function show(): JsonResponse
    {
        return response()->json(['inferred' => 'value that must lose']);
    }
}
