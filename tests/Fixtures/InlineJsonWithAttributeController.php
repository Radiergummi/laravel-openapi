<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

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

    #[ResponseResource(Article::class)]
    public function resourceAuthored(): JsonResponse
    {
        return response()->json(['data' => $this->buildResource()]);
    }

    #[FractalResponse(transformer: Article::class)]
    public function fractalAuthored(): JsonResponse
    {
        return response()->json(['data' => $this->buildResource()]);
    }

    /** @return array<string, mixed> */
    private function buildResource(): array
    {
        return [];
    }
}
