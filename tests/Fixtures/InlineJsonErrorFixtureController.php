<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// Calls below are deliberately unqualified — the realistic controller idiom — so the contributor's
// namespaced-fallback resolution (unresolved name → global Laravel helper) is exercised.
class InlineJsonErrorFixtureController extends Controller
{
    // region Whitelisted error shapes

    public function straightLineError(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    /**
     * The pervasive guarded-success + terminal-error-fallback idiom: the real success is
     * conditional, the straight-line literal is the error worth documenting.
     */
    public function guardedSuccessTerminalError(Request $request): JsonResponse
    {
        if ($request->has('admin')) {
            return response()->json(['ok' => true]);
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }

    public function conditionalError(Request $request): JsonResponse
    {
        if (! $request->has('token')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['ok' => true]);
    }

    public function serverError(): JsonResponse
    {
        return response()->json(['message' => 'Upstream failure'], 500);
    }

    public function setStatusCodeError(): JsonResponse
    {
        return response()->json(['error' => 'nope'])->setStatusCode(403);
    }

    public function notFoundError(): JsonResponse
    {
        return response()->json(['message' => 'Gone'], 404);
    }

    public function twoErrorBranches(Request $request): JsonResponse
    {
        if ($request->has('forbidden')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($request->has('missing')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['ok' => true]);
    }

    public function twoBranchesSameStatus(Request $request): JsonResponse
    {
        if ($request->has('a')) {
            return response()->json(['reason' => 'a'], 403);
        }

        return response()->json(['reason' => 'b'], 403);
    }

    // endregion

    // region Degradation & out of scope

    public function nonLiteralBody(Request $request): JsonResponse
    {
        return response()->json($request->all(), 403);
    }

    public function nonLiteralStatus(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Error'], $request->integer('code'));
    }

    public function redirectStatus(): JsonResponse
    {
        return response()->json(['url' => '/elsewhere'], 302);
    }

    public function successStatus(): JsonResponse
    {
        return response()->json(['created' => true], 201);
    }

    public function noJsonCall(): SymfonyResponse
    {
        return new SymfonyResponse('plain');
    }

    #[Response(status: 403, description: 'Authored forbidden')]
    public function authoredOverride(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    // endregion
}
