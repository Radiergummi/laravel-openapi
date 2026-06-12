<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Transformers;

use League\Fractal\TransformerAbstract;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * Composition fixture: the `#[TransformerField]` wins for `id`; `title` is only present in the
 * `transform()` literal and composes after the declared field.
 */
#[TransformerField('id', type: 'string', format: 'uuid')]
final class DeclaredAndInferredTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Article $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
        ];
    }
}
