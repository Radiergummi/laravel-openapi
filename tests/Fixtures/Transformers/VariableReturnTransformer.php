<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Transformers;

use League\Fractal\TransformerAbstract;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * Builds the transform array in a `$data` variable and returns it; reads like the inline literal.
 */
final class VariableReturnTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Article $article): array
    {
        $data = [
            'id' => $article->id,
            'title' => $article->title,
        ];

        return $data;
    }
}
