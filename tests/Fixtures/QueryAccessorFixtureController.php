<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\QueryParam;

class QueryAccessorFixtureController extends Controller
{
    // region Accessor shapes

    public function index(Request $request): JsonResponse
    {
        $sort = $request->query('sort');
        $search = $request->input('q');
        $name = $request->string('name');
        $page = $request->integer('page');
        $active = $request->boolean('active');

        return new JsonResponse([$sort, $search, $name, $page, $active]);
    }

    public function withDefaults(Request $request): JsonResponse
    {
        $sort = $request->query('sort', 'asc');
        $perPage = $request->integer('per_page', 25);
        $archived = $request->boolean('archived', false);
        $page = $request->query('page', 1);

        return new JsonResponse([$sort, $perPage, $archived, $page]);
    }

    public function namedArguments(Request $request): JsonResponse
    {
        $filter = $request->query(key: 'filter');
        $limit = $request->integer(default: 10, key: 'limit');

        return new JsonResponse([$filter, $limit]);
    }

    public function viaRequestHelper(): JsonResponse
    {
        $locale = request()->query('locale');

        return new JsonResponse([$locale]);
    }

    public function dottedKey(Request $request): JsonResponse
    {
        $filterName = $request->input('filter.name');

        return new JsonResponse([$filterName]);
    }

    public function conditionalRead(Request $request): JsonResponse
    {
        if ($request->boolean('compact')) {
            return new JsonResponse(['compact' => true]);
        }

        return new JsonResponse(['compact' => false]);
    }

    public function capturedClosureRead(Request $request): JsonResponse
    {
        $captured = collect(['a'])->map(fn(string $item): mixed => $request->query('captured'));
        $callback = function () use ($request): mixed {
            return $request->input('used');
        };

        return new JsonResponse([$captured, $callback()]);
    }

    public function duplicateReads(Request $request): JsonResponse
    {
        $pageString = $request->query('page');
        $page = $request->integer('page');
        $search = $request->query('q');
        $searchAgain = $request->input('q');

        return new JsonResponse([$pageString, $page, $search, $searchAgain]);
    }

    // endregion

    // region Degrade paths

    public function nonLiteralName(Request $request): JsonResponse
    {
        $sort = $request->query('sort');
        $dynamic = $request->query($this->dynamicKey());

        return new JsonResponse([$sort, $dynamic]);
    }

    public function wholeBag(Request $request): JsonResponse
    {
        $everything = $request->query();

        return new JsonResponse($everything);
    }

    public function lateRead(Request $request): JsonResponse
    {
        $first = 1;
        $second = 2;
        $third = 3;
        $fourth = 4;
        $fifth = 5;
        $sixth = 6;
        $seventh = 7;
        $eighth = 8;
        $ninth = 9;
        $tenth = 10;
        $late = $request->query('late');

        return new JsonResponse([
            $first, $second, $third, $fourth, $fifth,
            $sixth, $seventh, $eighth, $ninth, $tenth,
            $late,
        ]);
    }

    public function impostorReceiver(QueryAccessorLookalike $builder): JsonResponse
    {
        $scoped = $builder->query('status');
        $value = $builder->input('status');
        $count = $builder->integer('count');

        return new JsonResponse([$scoped, $value, $count]);
    }

    public function requestlessVariable(): JsonResponse
    {
        $request = new QueryAccessorLookalike();
        $scoped = $request->query('status');

        return new JsonResponse([$scoped]);
    }

    public function shadowedClosureRead(Request $request): JsonResponse
    {
        $sort = $request->query('outer');

        collect([])->each(function (Request $request): void {
            $request->query('inner');
        });

        return new JsonResponse([$sort]);
    }

    public function nonLiteralTypedName(Request $request): JsonResponse
    {
        $value = $request->integer($this->dynamicKey());

        return new JsonResponse([$value]);
    }

    // endregion

    // region #[QueryParam] precedence

    #[QueryParam('sort', description: 'Sort order.', type: 'string', enum: ['asc', 'desc'])]
    public function attributeOverride(Request $request): JsonResponse
    {
        $sort = $request->query('sort');
        $search = $request->query('q');

        return new JsonResponse([$sort, $search]);
    }

    // endregion

    // region GET inline-validate hand-off

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|max:100', // Free-text search query.
            'page' => 'integer|min:1',
        ]);

        return new JsonResponse($validated);
    }

    public function nestedSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter' => 'required|array',
            'filter.name' => 'required|string',
            'ids' => 'array',
            'ids.*' => 'integer',
            'rows' => 'array',
            'rows.*.price' => 'required|numeric',
        ]);

        return new JsonResponse($validated);
    }

    public function dynamicValidated(Request $request): JsonResponse
    {
        $rules = ['q' => 'required|string'];
        $validated = $request->validate($rules);

        return new JsonResponse($validated);
    }

    public function validatedAndRead(Request $request): JsonResponse
    {
        $search = $request->query('q');
        $validated = $request->validate([
            'q' => 'required|string|max:100',
        ]);

        return new JsonResponse([$search, $validated]);
    }

    // endregion

    private function dynamicKey(): string
    {
        return 'runtime';
    }
}
