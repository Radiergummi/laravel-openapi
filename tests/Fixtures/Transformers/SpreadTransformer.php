<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Transformers;

use League\Fractal\TransformerAbstract;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * A literal with a spread entry — the spread's keys are not statically known, so the whole
 * literal is refused.
 */
final class SpreadTransformer extends TransformerAbstract
{
    private const array BASE = ['kind' => 'article'];

    /**
     * @return array<string, mixed>
     */
    public function transform(Article $article): array
    {
        return [
            ...self::BASE,
            'id' => $article->id,
        ];
    }
}
