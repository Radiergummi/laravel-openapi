<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

class FindOrFailFixtureController extends Controller
{
    // region Whitelisted call shapes

    public function staticFindOrFail(int $id): JsonResponse
    {
        $article = Article::findOrFail($id);

        return new JsonResponse($article);
    }

    public function queryFindOrFail(int $id): JsonResponse
    {
        $article = Article::query()->findOrFail($id);

        return new JsonResponse($article);
    }

    public function firstOrFail(Request $request): JsonResponse
    {
        $article = Article::where('slug', $request->input('slug'))->firstOrFail();

        return new JsonResponse($article);
    }

    public function findOrFailInGuard(int $id): JsonResponse
    {
        if ($id > 0) {
            $article = Article::findOrFail($id);

            return new JsonResponse($article);
        }

        return new JsonResponse([]);
    }

    public function mixedCaseFindOrFail(int $id): JsonResponse
    {
        // PHP dispatches this to the same method regardless of casing.
        $article = Article::FindOrFail($id);

        return new JsonResponse($article);
    }

    public function boundAndFindOrFail(Article $article, int $id): JsonResponse
    {
        $related = Article::findOrFail($id);

        return new JsonResponse([$article, $related]);
    }

    // endregion

    // region Out of scope: not the throwing idioms

    public function nonThrowingFind(int $id): JsonResponse
    {
        $article = Article::find($id);
        $fallback = Article::query()->firstOr(static fn(): null => null);

        return new JsonResponse([$article, $fallback]);
    }

    public function noLookupAtAll(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    public function findOrFailBeyondStatementLimit(): JsonResponse
    {
        $a = 1;
        $b = 2;
        $c = 3;
        $d = 4;
        $e = 5;
        $f = 6;
        $g = 7;
        $h = 8;
        $i = 9;
        $j = 10;

        $article = Article::findOrFail($a + $b + $c + $d + $e + $f + $g + $h + $i + $j);

        return new JsonResponse($article);
    }

    // endregion
}
