<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Transformers;

use League\Fractal\TransformerAbstract;

/**
 * A readable literal whose parameter carries no model type — `$article->id` cannot be resolved
 * against any schema, so the key stays an unconstrained property.
 */
final class UntypedParameterTransformer extends TransformerAbstract
{
    /**
     * @param mixed $article
     *
     * @return array<string, mixed>
     */
    public function transform($article): array
    {
        return [
            'id' => $article->id,
            'kind' => 'article',
        ];
    }
}
