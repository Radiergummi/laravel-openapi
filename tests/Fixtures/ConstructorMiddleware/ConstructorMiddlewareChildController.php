<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware;

use Illuminate\Http\JsonResponse;

/**
 * No own constructor — the middleware comes from {@see ConstructorMiddlewareBaseController}.
 */
class ConstructorMiddlewareChildController extends ConstructorMiddlewareBaseController
{
    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
