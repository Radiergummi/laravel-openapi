<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Response;

/**
 * Test fixture — a `#[Response]` whose literal `schema` carries a nested object (`properties`) and
 * a nested array (`items`). Routes wired in {@see \Radiergummi\OpenApi\Tests\Feature\NestedResponseSchemaTest}.
 */
class NestedResponseSchemaFixtureController extends Controller
{
    #[Response(status: 200, description: 'Operation result.', schema: [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['message'],
    ])]
    public function show(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
