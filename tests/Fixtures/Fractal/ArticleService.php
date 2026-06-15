<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;

/**
 * An unrelated service that happens to expose `item()` / `collection()` methods — used to prove
 * the binding does not fire on non-Fractal receivers.
 */
final class ArticleService
{
    /**
     * @return array<string, mixed>
     */
    public function item(Article $article, InferredArticleTransformer $transformer): array
    {
        return $transformer->transform($article);
    }

    /**
     * @param list<string> $rows
     *
     * @return list<string>
     */
    public function collection(array $rows, InferredArticleTransformer $transformer): array
    {
        return $rows;
    }
}
