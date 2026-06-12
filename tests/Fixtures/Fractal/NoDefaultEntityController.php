<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * The call shape matches but `$entity_transformer` carries no concrete transformer default —
 * the resolver degrades with a generation-log note.
 */
final class NoDefaultEntityController extends BaseEntityController
{
    /** Show an article. */
    public function show(): JsonResponse
    {
        return $this->itemResponse(new Article());
    }
}
