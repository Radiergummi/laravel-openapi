<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;

/**
 * Controllers constructing `League\Fractal\Resource\Item` / `Collection` directly and serialising
 * them through an injected `Manager` — the third recognised invocation style.
 */
final class ManagerFractalController extends Controller
{
    public function __construct(private readonly Manager $fractal) {}

    public function item(): JsonResponse
    {
        $resource = new Item(new Article(), new InferredArticleTransformer());

        return new JsonResponse($this->fractal->createData($resource)->toArray());
    }

    public function collection(): JsonResponse
    {
        $resource = new Collection(Article::query()->get(), new InferredArticleTransformer());

        return new JsonResponse($this->fractal->createData($resource)->toArray());
    }
}
