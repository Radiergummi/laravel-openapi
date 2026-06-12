<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Transformers;

use League\Fractal\TransformerAbstract;

/**
 * A perfectly readable `transform()` literal that yields no fields — the empty-but-not-dynamic
 * refusal case: the reader succeeds, but there is nothing to document.
 */
final class EmptyTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        return [];
    }
}
