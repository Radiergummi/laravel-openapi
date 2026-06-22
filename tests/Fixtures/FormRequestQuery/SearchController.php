<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\QueryParam;

/**
 * Exercises the FormRequest query-parameter source across HTTP verbs.
 */
class SearchController
{
    public function index(SearchFormRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function store(SearchFormRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function destroy(SearchFormRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function arrayOfObjects(ArrayOfObjectsFormRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function throwing(ThrowingRulesFormRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[QueryParam('term', required: true, type: 'string', description: 'Authored search term.')]
    public function indexWithAttribute(SearchFormRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }
}
