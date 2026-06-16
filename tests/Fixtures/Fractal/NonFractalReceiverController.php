<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;

/**
 * `item()` / `collection()` calls whose receiver is NOT the `fractal()` helper or the Fractalistic
 * facade — an unrelated service or query builder. These must never be mistaken for a Fractal
 * binding, even though the method names and a literal transformer-like argument coincide.
 */
final class NonFractalReceiverController extends Controller
{
    public function __construct(private readonly ArticleService $service) {}

    public function viaService(): JsonResponse
    {
        return new JsonResponse($this->service->item(new Article(), new InferredArticleTransformer()));
    }

    public function viaQuery(): JsonResponse
    {
        return new JsonResponse($this->service->collection(['a'], new InferredArticleTransformer()));
    }
}
