<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\EmptyTransformer;

/**
 * The call shape matches and `$entity_transformer` defaults to a real transformer, but its
 * `transform()` literal is readable and *empty* — the resolver refuses with a note rather
 * than binding an envelope around a schema that documents nothing.
 */
final class EmptyTransformerEntityController extends BaseEntityController
{
    protected $entity_transformer = EmptyTransformer::class;

    /** Show an article. */
    public function show(): JsonResponse
    {
        return $this->itemResponse(new Article());
    }
}
