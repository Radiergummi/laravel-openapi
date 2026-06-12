<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InlineValidationFixtureController extends Controller
{
    use ValidatesRequests;

    /** @var array<string, mixed> */
    protected $rules = [
        'title' => 'required|string|max:120',
        'body' => ['nullable', 'string'],
    ];

    // region Shape 1: $request->validate([...])

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255', // The display name.
            'email' => ['required', 'email'], // The contact address.
            'age' => 'nullable|integer|min:18',
            'tags' => 'array',
            'tags.*' => 'string',
        ]);

        return new JsonResponse($validated, 201);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'avatar' => 'required|file',
        ]);

        return new JsonResponse($validated, 201);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string',
        ]);

        return new JsonResponse($validated);
    }

    public function partiallyDynamic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['draft', 'published'])],
            'callback' => $this->callbackRules(),
            'note' => 'nullable|string',
        ]);

        return new JsonResponse($validated);
    }

    // endregion

    // region Shape 2: $this->validate($request, [...])

    public function update(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'title' => 'required|string',
        ]);

        return new JsonResponse($data);
    }

    public function viaRequestHelper(): JsonResponse
    {
        $data = $this->validate(request(), [
            'locale' => 'required|string',
        ]);

        return new JsonResponse($data);
    }

    // endregion

    // region Shape 3: Validator::make($request->all(), [...])

    public function viaValidatorFacade(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'amount' => 'required|numeric',
        ])->validate();

        return new JsonResponse($validated);
    }

    // endregion

    // region Shape 4: Request::validate([...]) (facade)

    public function viaRequestFacade(): JsonResponse
    {
        $validated = RequestFacade::validate([
            'token' => 'required|string',
        ]);

        return new JsonResponse($validated);
    }

    // endregion

    // region Controller-declared rules

    public function fromRulesProperty(Request $request): JsonResponse
    {
        $data = $this->validate($request, $this->rules);

        return new JsonResponse($data);
    }

    public function fromKeyedRulesMethod(Request $request): JsonResponse
    {
        $data = $this->validate($request, $this->rulesByAction()['create']);

        return new JsonResponse($data, 201);
    }

    /** @return array<string, array<string, mixed>> */
    protected function rulesByAction(): array
    {
        return [
            'create' => [
                'name' => ['required', 'string'],
                'description' => 'nullable|string',
            ],
        ];
    }

    // endregion

    // region Conditional contexts (must not match)

    public function conditionalTernary(Request $request): JsonResponse
    {
        $data = $request->isMethod('POST') ? $request->validate(['c' => 'required']) : [];

        return new JsonResponse($data);
    }

    public function conditionalShortCircuit(Request $request): JsonResponse
    {
        $request->has('guard') && $request->validate(['d' => 'required']);

        return new JsonResponse([]);
    }

    public function conditionalInsideClosure(Request $request): JsonResponse
    {
        $result = DB::transaction(function () use ($request): array {
            if ($request->has('e')) {
                return $request->validate(['e' => 'required']);
            }

            return [];
        });

        return new JsonResponse($result);
    }

    // endregion

    // region Degrade paths

    public function dynamicRules(Request $request): JsonResponse
    {
        $rules = ['name' => 'required'];
        $validated = $request->validate($rules);

        return new JsonResponse($validated);
    }

    public function lateValidate(Request $request): JsonResponse
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
        $validated = $request->validate([
            'unreachable' => 'required|string',
        ]);

        return new JsonResponse([
            $first, $second, $third, $fourth, $fifth,
            $sixth, $seventh, $eighth, $ninth, $tenth,
            $validated,
        ]);
    }

    public function withoutValidation(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** @return array<int, mixed> */
    private function callbackRules(): array
    {
        return ['nullable', 'url'];
    }

    // endregion
}
