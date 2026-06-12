<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Transformers;

use League\Fractal\TransformerAbstract;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * A dynamic `transform()` body — the return expression is a variable, not an array literal —
 * outside the bounded case; the reader must refuse it.
 */
final class DynamicBodyTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Article $article): array
    {
        $data = ['id' => $article->id];

        $data['title'] = $article->title;

        return $data;
    }
}
