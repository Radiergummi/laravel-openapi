<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;

use function config;

/**
 * Classic constructor-middleware controller that the container cannot instantiate (see
 * {@see UnresolvableSigningKey}), exercising every whitelisted registration shape plus the
 * degrade cases.
 */
class ConstructorMiddlewareFixtureController extends Controller
{
    public function __construct(public readonly UnresolvableSigningKey $signingKey)
    {
        // Unscoped registration: applies to every action.
        $this->middleware('auth:sanctum');

        // Fluent only() with an array literal.
        $this->middleware('verified')->only(['index']);

        // Fluent except() with a bare string (Arr::wrap semantics).
        $this->middleware('throttle:exports')->except('index');

        // Options-array scoping, array form of the middleware argument.
        $this->middleware(['signed.params'], ['only' => ['store']]);

        // Non-literal middleware name: refused, reported as unreadable.
        $this->middleware($this->dynamicMiddlewareName());

        // Conditionally applied: refused, reported as conditional.
        if (config('app.debug') === true) {
            $this->middleware('debugbar');
        }

        // Receiver discipline: not our registration, invisible to the scan.
        $aliased = $this;
        $aliased->middleware('aliased-receiver');
    }

    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function store(): JsonResponse
    {
        return new JsonResponse([], 201);
    }

    #[PublicEndpoint]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    private function dynamicMiddlewareName(): string
    {
        return 'computed';
    }
}
