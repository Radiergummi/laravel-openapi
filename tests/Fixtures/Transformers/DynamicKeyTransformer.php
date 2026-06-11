<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Transformers;

use League\Fractal\TransformerAbstract;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * A literal with a dynamic key — the structure is unknowable, so the whole literal is refused
 * rather than partially documented under guessed keys.
 */
final class DynamicKeyTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Article $article): array
    {
        return [
            $this->keyName() => $article->id,
            'title' => $article->title,
        ];
    }

    private function keyName(): string
    {
        return 'id';
    }
}
