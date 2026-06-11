<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Transformers;

use League\Fractal\TransformerAbstract;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * The canonical bounded `transform()` shape (#13): a single top-level `return [...]` literal
 * whose values mix model-parameter fetches, casts, literals, and one unresolvable method call.
 */
final class InferredArticleTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Article $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'published_at' => $article->published_at,
            'word_count' => (int) $article->subtitle,
            'price' => (float) $article->id,
            'archived' => (bool) $article->subtitle,
            'kind' => 'article',
            'flags' => ['featured' => true],
            'permalink' => $this->buildPermalink($article),
        ];
    }

    private function buildPermalink(Article $article): string
    {
        return '/articles/' . $article->id;
    }
}
