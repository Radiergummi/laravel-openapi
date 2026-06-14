<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function redirect;

// Calls below are deliberately unqualified — the realistic controller idiom — so the contributor's
// namespaced-fallback resolution (unresolved name → global Laravel helper) is what gets exercised.
class AbortFixtureController extends Controller
{
    // region Whitelisted call shapes

    public function plainAbort(): never
    {
        abort(403);
    }

    public function abortWithMessage(): never
    {
        abort(404, 'Order not found');
    }

    public function abortIf(Request $request): JsonResponse
    {
        abort_if($request->user() === null, 401, 'Sign in first');

        return new JsonResponse([]);
    }

    public function abortUnless(Request $request): JsonResponse
    {
        abort_unless($request->boolean('admin'), 403, 'Admins only');

        return new JsonResponse([]);
    }

    public function guardedAbort(Request $request): JsonResponse
    {
        if (!$request->has('token')) {
            abort(401);
        }

        return new JsonResponse([]);
    }

    public function multipleStatuses(Request $request): JsonResponse
    {
        abort_if($request->user() === null, 401);
        abort_unless($request->boolean('admin'), 403, 'Admins only');

        if (!$request->has('order')) {
            abort(404, 'Order not found');
        }

        return new JsonResponse([]);
    }

    public function serverErrorAbort(): never
    {
        abort(500, 'Upstream failure');
    }

    // The Koel idiom (#227): the status as a class constant on an imported, aliased class.
    public function classConstantStatus(): never
    {
        abort(SymfonyResponse::HTTP_FORBIDDEN, 'Cannot update a user prospect.');
    }

    public function namedAbort(): never
    {
        abort(code: 403, message: 'Named forbidden');
    }

    public function namedAbortIf(Request $request): JsonResponse
    {
        abort_if($request->user() === null, code: 404);

        return new JsonResponse([]);
    }

    // The AdvisingApp idiom: named boolean/code/message arguments.
    public function namedAbortUnless(Request $request): JsonResponse
    {
        abort_unless(
            boolean: $request->boolean('admin'),
            code: 403,
            message: 'Named admins only',
        );

        return new JsonResponse([]);
    }

    // Named arguments may appear in any order, ahead of the condition.
    public function reorderedNamedAbortIf(Request $request): JsonResponse
    {
        abort_if(code: 403, boolean: $request->user() === null);

        return new JsonResponse([]);
    }

    public function mixedPositionalAndNamedAbort(): never
    {
        abort(403, message: 'Mixed forbidden');
    }

    public function unknownNamedArgument(Request $request): JsonResponse
    {
        abort_if(boolean: $request->boolean('x'), status: 403);

        return new JsonResponse([]);
    }

    // endregion

    // region Off-whitelist and out-of-scope shapes

    public function dynamicStatus(Request $request): never
    {
        $status = (int) $request->input('status');

        abort($status);
    }

    public function responseArgument(): never
    {
        abort(new Response('Order not found', 404));
    }

    public function dynamicMessage(Request $request): never
    {
        abort(404, (string) $request->input('reason'));
    }

    public function redirectAbort(): never
    {
        abort(302, '', ['Location' => '/somewhere-else']);
    }

    public function abortBeyondStatementLimit(): never
    {
        $one = 1;
        $two = 2;
        $three = 3;
        $four = 4;
        $five = 5;
        $six = 6;
        $seven = 7;
        $eight = 8;
        $nine = 9;
        $ten = $one + $two + $three + $four + $five + $six + $seven + $eight + $nine;

        abort(403, (string) $ten);
    }

    public function firstClassCallable(): JsonResponse
    {
        $aborter = abort(...);

        return new JsonResponse(['guard' => $aborter]);
    }

    public function noAbortAtAll(): JsonResponse
    {
        $response = redirect('/elsewhere');

        return new JsonResponse(['target' => $response->getTargetUrl()]);
    }

    // endregion
}
