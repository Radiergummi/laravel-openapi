<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// The response() calls below are deliberately unqualified — the realistic controller idiom — so
// the resolver's namespaced-fallback resolution (unresolved name → global Laravel helper) is what
// gets exercised.
class InlineJsonFixtureController extends Controller
{
    // region Literal bodies

    public function literalObject(): JsonResponse
    {
        return response()->json([
            'message' => 'Order created',
            'success' => true,
            'attempts' => 3,
            'score' => 0.5,
        ]);
    }

    public function nestedLiteral(): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => 1,
                'tags' => ['a', 'b'],
            ],
        ]);
    }

    public function listOfScalars(): JsonResponse
    {
        return response()->json([1, 2, 3]);
    }

    public function listOfObjects(): JsonResponse
    {
        return response()->json([
            ['id' => 1, 'name' => 'first'],
            ['id' => 2, 'name' => 'second'],
        ]);
    }

    public function partialLiteral(): JsonResponse
    {
        return response()->json([
            'logs' => $this->buildLogs(),
            'success' => true,
        ]);
    }

    private function buildLogs(): string
    {
        return 'log output';
    }

    public function chainedCall(): JsonResponse
    {
        return response()->json(['cached' => true])->header('X-Cache', 'hit');
    }

    public function noContentExplicitStatus(): SymfonyResponse
    {
        return response()->noContent(200);
    }

    public function noContent(): SymfonyResponse
    {
        return response()->noContent();
    }

    public function noContentNamedStatus(): SymfonyResponse
    {
        return response()->noContent(status: 202);
    }

    public function noContentDynamicStatus(Request $request): SymfonyResponse
    {
        return response()->noContent($request->integer('status'));
    }

    public function noContentNonSuccess(): SymfonyResponse
    {
        return response()->noContent(404);
    }

    public function setStatusCodeLiteral(): JsonResponse
    {
        return response()->json(['created' => true])->setStatusCode(201);
    }

    public function setStatusCodeConstant(): JsonResponse
    {
        return response()->json(['created' => true])->setStatusCode(SymfonyResponse::HTTP_CREATED);
    }

    public function setStatusCodeDynamic(Request $request): JsonResponse
    {
        return response()->json(['created' => true])->setStatusCode($request->integer('status'));
    }

    public function setStatusCodeNonSuccess(): JsonResponse
    {
        return response()->json(['error' => 'nope'])->setStatusCode(403);
    }

    public function setDataChain(): JsonResponse
    {
        return response()->json(['created' => true])->setData(['replaced' => true]);
    }

    public function integerKeyedList(): JsonResponse
    {
        return response()->json([0 => 'first', 1 => 'second']);
    }

    public function integerKeyedListConstant(): JsonResponse
    {
        return response()->json(LiteralConstantsFixture::LIST_WITH_EXPLICIT_INTEGER_KEYS);
    }

    // endregion

    // region Status arguments

    public function assignedThenReturned(): JsonResponse
    {
        $preview = response()->json(['first' => true]);

        return response()->json(['second' => true], 201);
    }

    public function literalStatus(): JsonResponse
    {
        return response()->json(['id' => 1], 201);
    }

    public function classConstantStatus(): JsonResponse
    {
        return response()->json(['id' => 1], SymfonyResponse::HTTP_ACCEPTED);
    }

    public function namedArguments(): JsonResponse
    {
        return response()->json(status: 201, data: ['queued' => true]);
    }

    public function dynamicStatus(Request $request): JsonResponse
    {
        return response()->json(['ok' => true], $request->integer('status'));
    }

    /**
     * The pervasive guarded-success + terminal-error-fallback idiom (InvoiceNinja's
     * `StripeController::verify` shape): the real success is conditional, the straight-line
     * literal is an error. The 403 must not claim the operation's primary response.
     */
    public function guardedSuccessWithTerminalError(Request $request): JsonResponse
    {
        if ($request->has('admin')) {
            return $this->buildFallback();
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }

    private function buildFallback(): JsonResponse
    {
        return new JsonResponse(['fallback' => true]);
    }

    // endregion

    // region Refused shapes

    public function noContentStatus(): JsonResponse
    {
        return response()->json(['gone' => true], 204);
    }

    public function nonsenseStatus(): JsonResponse
    {
        return response()->json(['gone' => true], 999);
    }

    public function variableBody(): JsonResponse
    {
        $payload = ['ok' => true];

        return response()->json($payload);
    }

    public function modelBody(): JsonResponse
    {
        return response()->json(Article::query()->firstOrFail());
    }

    // endregion

    // region Silent skips

    public function dynamicKey(Request $request): JsonResponse
    {
        return response()->json([
            $request->string('key')->toString() => 'value',
        ]);
    }

    public function conditionalOnly(Request $request): JsonResponse
    {
        if ($request->has('verbose')) {
            return response()->json(['verbose' => true]);
        }

        return $this->buildFallback();
    }

    public function emptyJson(): JsonResponse
    {
        return response()->json();
    }

    public function emptyArrayBody(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * The #265 repro: a list whose elements are non-literal collapses to a `type: array` with an
     * unknown item schema. The emitted `items` must still be present or `openapi:generate`'s
     * validation pass rejects the document.
     */
    public function unreadableListBody(): JsonResponse
    {
        return response()->json(['items' => [random_int(0, 1), random_int(2, 3)]]);
    }

    public function noJsonCall(): JsonResponse
    {
        return $this->buildFallback();
    }

    // endregion

    // region Helpers

    public function beyondStatementLimit(): JsonResponse
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

        return response()->json([
            'sum' => $first + $second + $third + $fourth + $fifth
                + $sixth + $seventh + $eighth + $ninth + $tenth,
        ]);
    }

    /**
     * Degenerate on purpose: the declared Eloquent return type contradicts the body. The
     * return-type guard must keep the body scan away from any action whose signature already
     * carries schema information (Tier-0 wins).
     */
    public function typedReturnWithJsonBody(): Article
    {
        /** @phpstan-ignore return.type (deliberately contradicts the declared type: the return-type guard must win) */
        return response()->json(['stolen' => true]);
    }

    // endregion
}
