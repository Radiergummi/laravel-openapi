<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Header;
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

    // region Cookie & header reads

    public function cookieAndHeaderReads(Request $request): JsonResponse
    {
        $sort = $request->query('sort');
        $session = $request->cookie('session');
        $apiKey = $request->header('X-Api-Key');

        return new JsonResponse([$sort, $session, $apiKey]);
    }

    public function sameNameAcrossLocations(Request $request): JsonResponse
    {
        $queryToken = $request->query('token');
        $cookieToken = $request->cookie('token');
        $headerToken = $request->header('token');

        return new JsonResponse([$queryToken, $cookieToken, $headerToken]);
    }

    public function dottedHeaderName(Request $request): JsonResponse
    {
        $value = $request->header('X.Y');

        return new JsonResponse([$value]);
    }

    public function dynamicCookieName(Request $request): JsonResponse
    {
        $value = $request->cookie($this->dynamicKey());

        return new JsonResponse([$value]);
    }

    // endregion

    // region Reserved-header denylist

    public function reservedHeaderReads(Request $request): JsonResponse
    {
        $auth = $request->header('Authorization');
        $contentType = $request->header('Content-Type');
        $contentLength = $request->header('Content-Length');
        $accept = $request->header('Accept');
        $ifNoneMatch = $request->header('If-None-Match');
        $host = $request->header('Host');

        return new JsonResponse([$auth, $contentType, $contentLength, $accept, $ifNoneMatch, $host]);
    }

    public function caseInsensitiveReservedHeaders(Request $request): JsonResponse
    {
        $lower = $request->header('content-type');
        $upper = $request->header('CONTENT-TYPE');
        $mixed = $request->header('Content-Type');

        return new JsonResponse([$lower, $upper, $mixed]);
    }

    public function customHeaderReads(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-Api-Key');
        $signature = $request->header('Stripe-Signature');
        $forwardedFor = $request->header('X-Forwarded-For');

        return new JsonResponse([$apiKey, $signature, $forwardedFor]);
    }

    /** An explicit #[Header] is authoritative: the denylist never touches the attribute path. */
    #[Header('Authorization', description: 'Bearer token.')]
    public function authorizationHeaderAttribute(Request $request): JsonResponse
    {
        $auth = $request->header('Authorization');

        return new JsonResponse([$auth]);
    }

    public function reservedNameOnCookie(Request $request): JsonResponse
    {
        // The denylist is header-location only: a cookie of the same name is not filtered.
        $value = $request->cookie('Content-Type');

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

    #[QueryParam('per_page', nullable: true)]
    public function untypedNullableParam(Request $request): JsonResponse
    {
        return new JsonResponse([$request->query('per_page')]);
    }

    #[QueryParam('ids', type: 'array')]
    public function arrayAttributeParam(Request $request): JsonResponse
    {
        return new JsonResponse([$request->query('ids')]);
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

    public function enumArraySearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tags' => 'array',
            'tags.*' => 'nullable|in:red,green,blue',
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
