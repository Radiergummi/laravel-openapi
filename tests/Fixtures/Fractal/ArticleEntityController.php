<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\DeclaredAndInferredTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;

final class ArticleEntityController extends BaseEntityController
{
    protected $entity_transformer = InferredArticleTransformer::class;

    /** Show an article. */
    public function show(): JsonResponse
    {
        $article = new Article();

        return $this->itemResponse($article);
    }

    /** List articles. */
    public function index(): JsonResponse
    {
        return $this->listResponse(Article::query());
    }

    /** Reassigns the transformer at runtime. */
    public function reassigned(): JsonResponse
    {
        $this->entity_transformer = DeclaredAndInferredTransformer::class;

        return $this->itemResponse(new Article());
    }

    /** The explicit attribute wins over the body scan. */
    #[FractalResponse(transformer: DeclaredAndInferredTransformer::class)]
    public function attributed(): JsonResponse
    {
        return $this->itemResponse(new Article());
    }

    /** No whitelisted call shape — plain response. */
    public function plain(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
