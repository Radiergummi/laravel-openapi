<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;
use Spatie\Fractal\Fractal;

/**
 * Controllers using the `Spatie\Fractal\Fractal::create()` facade-style entrypoint, chained into
 * `item()` / `collection()`.
 */
final class FacadeFractalController extends Controller
{
    public function item(): JsonResponse
    {
        return Fractal::create()->item(new Article(), new InferredArticleTransformer())->respond();
    }

    public function collection(): JsonResponse
    {
        return Fractal::create()
            ->collection(Article::query()->get(), new InferredArticleTransformer())
            ->respond();
    }
}
