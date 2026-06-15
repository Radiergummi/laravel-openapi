<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\Serializer\JsonApiSerializer;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\EmptyTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;

use function fractal;

/**
 * Controllers using the `fractal()` helper and its chained `item()` / `collection()` shapes,
 * terminated with `respond()` — the dominant in-the-wild Fractal invocation style.
 */
final class HelperFractalController extends Controller
{
    public function item(): JsonResponse
    {
        return fractal()->item(new Article(), new InferredArticleTransformer())->respond();
    }

    public function collection(): JsonResponse
    {
        return fractal()->collection(Article::query()->get(), new InferredArticleTransformer())->respond();
    }

    public function classConstTransformer(): JsonResponse
    {
        return fractal()->item(new Article(), InferredArticleTransformer::class)->respond();
    }

    public function arraySerializer(): JsonResponse
    {
        return fractal()
            ->collection(Article::query()->get(), new InferredArticleTransformer())
            ->serializeWith(new ArraySerializer())
            ->respond();
    }

    public function jsonApiSerializer(): JsonResponse
    {
        return fractal()
            ->item(new Article(), new InferredArticleTransformer())
            ->serializeWith(new JsonApiSerializer())
            ->respond();
    }

    /** Bare two-arg helper: item vs collection is not statically knowable — refused. */
    public function bareTwoArgument(): JsonResponse
    {
        return fractal(new Article(), new InferredArticleTransformer())->respond();
    }

    /** The transformer is a variable, not a literal — refused. */
    public function variableTransformer(): JsonResponse
    {
        $transformer = new InferredArticleTransformer();

        return fractal()->item(new Article(), $transformer)->respond();
    }

    /** The transformer yields no documentable fields — refused with a note. */
    public function emptyTransformer(): JsonResponse
    {
        return fractal()->item(new Article(), new EmptyTransformer())->respond();
    }

    /** An unrecognised serializer — refused rather than guessing the envelope. */
    public function unknownSerializer(): JsonResponse
    {
        return fractal()
            ->item(new Article(), new InferredArticleTransformer())
            ->serializeWith(new CustomSerializer())
            ->respond();
    }
}
