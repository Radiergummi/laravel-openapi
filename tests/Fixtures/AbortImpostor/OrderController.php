<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\AbortImpostor;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * A same-namespace user-defined abort() — defined in this file so it is guaranteed to be loaded
 * whenever the controller class is. An unqualified `abort(...)` in this namespace resolves to it,
 * not to Laravel's global helper, so the contributor must not match.
 */
function abort(int $code, string $message = ''): JsonResponse
{
    // Deliberately does not throw — this is not Laravel's helper.
    return new JsonResponse(['code' => $code, 'message' => $message]);
}

class OrderController extends Controller
{
    public function destroy(): JsonResponse
    {
        return abort(404, 'Order not found'); // The impostor above, not Laravel's helper.
    }
}
