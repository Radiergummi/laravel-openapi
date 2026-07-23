<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Base controller declaring the body-less response helpers a subclass action returns. The helpers
 * live on an ancestor on purpose: the reader must reflect a helper up the class hierarchy.
 *
 * `response()` is left unqualified (the realistic idiom), exercising the global-helper fallback.
 */
abstract class SameClassHelperBaseController extends Controller
{
    public function __construct(protected ResponseFactory $responseFactory) {}

    // region Body-less helpers (resolve)

    protected function empty(int $status = SymfonyResponse::HTTP_NO_CONTENT): JsonResponse
    {
        return new JsonResponse(status: $status);
    }

    /** A response factory reached through a same-class accessor method. */
    protected function getResponseFactory(): ResponseFactory
    {
        return response();
    }

    /**
     * The motivating shape: a body-less status built through a factory accessor and returned via a
     * whitelisted header chain. The status comes from this helper's own `status` default.
     */
    protected function emptyViaFactory(int $status = SymfonyResponse::HTTP_NO_CONTENT): SymfonyResponse
    {
        return $this->getResponseFactory()->make(status: $status)->withHeaders(['X-Trace' => 'on']);
    }

    /** A factory reached through a typed property receiver rather than an accessor method. */
    protected function emptyViaFactoryProperty(): SymfonyResponse
    {
        return $this->responseFactory->make(status: SymfonyResponse::HTTP_NO_CONTENT);
    }

    protected function noContentHelper(): SymfonyResponse
    {
        return response()->noContent();
    }

    protected function makeEmptyPositional(): SymfonyResponse
    {
        return response()->make('', SymfonyResponse::HTTP_NO_CONTENT);
    }

    protected function newResponseNoContent(): Response
    {
        return new Response(status: SymfonyResponse::HTTP_NO_CONTENT);
    }

    /** A body-less construction returned through a whitelisted header chain. */
    protected function jsonResponseWithHeaders(): JsonResponse
    {
        return (new JsonResponse(status: SymfonyResponse::HTTP_NO_CONTENT))->withHeaders(['X-Trace' => 'on']);
    }

    // endregion

    // region Body-less helpers that refuse

    /** A body-mutating trailing chain: not body-less. */
    protected function bodyMutatingChain(): SymfonyResponse
    {
        return response()->make(status: SymfonyResponse::HTTP_NO_CONTENT)->setContent('surprise');
    }

    /** Reached through a variable, so a later ->setData() would be invisible. */
    protected function cached(int $status = SymfonyResponse::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse(status: $status);
        $response->setData(['cached' => true]);

        return $response;
    }

    /** Reached through a variable even without a mutation: the gate keys on directness. */
    protected function assignedNoContent(): JsonResponse
    {
        $response = new JsonResponse(status: SymfonyResponse::HTTP_NO_CONTENT);

        return $response;
    }

    /** Delegates to another same-class helper through a variable (the deferred hop). */
    protected function accepted(): JsonResponse
    {
        $response = $this->empty(SymfonyResponse::HTTP_ACCEPTED);

        return $response;
    }

    /** Delegates directly to another same-class helper: still refused, the reader never hops. */
    protected function acceptedDirect(): JsonResponse
    {
        return $this->empty(SymfonyResponse::HTTP_ACCEPTED);
    }

    /** A body-less construction defaulting to a non-2xx status. */
    protected function serverError(int $status = SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR): SymfonyResponse
    {
        return response()->noContent($status);
    }

    /** Branches, so there is no single unconditional return to read. */
    protected function multiStatus(bool $flag): SymfonyResponse
    {
        if ($flag) {
            return response()->noContent(SymfonyResponse::HTTP_OK);
        }

        return response()->noContent(SymfonyResponse::HTTP_NO_CONTENT);
    }

    // endregion

    // region Body-bearing helpers (silently skipped)

    /** A positional make() puts the body in argument 0, so `make(204)` documents a body, not a 204. */
    protected function positionalMake(): SymfonyResponse
    {
        /** @phpstan-ignore argument.type (deliberate: a positional int is content, not status) */
        return response()->make(204);
    }

    /** @param array<string, mixed> $data */
    protected function ok(array $data): JsonResponse
    {
        return response()->json($data);
    }

    /** A factory make() carrying a body: body-bearing, so it must not read as contentless. */
    protected function factoryMakeWithBody(): SymfonyResponse
    {
        return $this->getResponseFactory()->make('body', SymfonyResponse::HTTP_OK);
    }

    /** A ->make() on a receiver whose declared type is not a ResponseFactory: the guard refuses. */
    protected function nonFactoryMake(): SymfonyResponse
    {
        return $this->widget()->make(status: SymfonyResponse::HTTP_NO_CONTENT);
    }

    private function widget(): NotAResponseFactory
    {
        return new NotAResponseFactory();
    }

    // endregion
}

/** A factory-shaped object that is deliberately NOT a Laravel ResponseFactory. */
class NotAResponseFactory
{
    public function make(int $status = SymfonyResponse::HTTP_OK): SymfonyResponse
    {
        return new SymfonyResponse(status: $status);
    }
}

/**
 * Actions returning the inherited helpers. Loose response return types keep the body scan in
 * scope (a schema-bearing return type would bypass it).
 */
class SameClassHelperController extends SameClassHelperBaseController
{
    // region Resolve to a body-less status

    public function destroy(): SymfonyResponse
    {
        return $this->empty();
    }

    public function reset(): SymfonyResponse
    {
        return $this->empty(SymfonyResponse::HTTP_RESET_CONTENT);
    }

    public function resetNamed(): SymfonyResponse
    {
        return $this->empty(status: SymfonyResponse::HTTP_RESET_CONTENT);
    }

    public function destroyChainedHeaders(): JsonResponse
    {
        return $this->empty()->withHeaders(['X-Trace' => 'on']);
    }

    public function viaBodyChain(): SymfonyResponse
    {
        return $this->jsonResponseWithHeaders();
    }

    public function viaNoContent(): SymfonyResponse
    {
        return $this->noContentHelper();
    }

    public function viaMake(): SymfonyResponse
    {
        return $this->makeEmptyPositional();
    }

    public function viaNewResponse(): SymfonyResponse
    {
        return $this->newResponseNoContent();
    }

    public function viaFactory(): SymfonyResponse
    {
        return $this->emptyViaFactory();
    }

    public function viaFactoryProperty(): SymfonyResponse
    {
        return $this->emptyViaFactoryProperty();
    }

    // endregion

    // region Refuse with a note

    public function dynamicStatus(Request $request): SymfonyResponse
    {
        return $this->empty($request->integer('status'));
    }

    public function viaServerError(): SymfonyResponse
    {
        return $this->serverError();
    }

    public function viaBodyMutating(): SymfonyResponse
    {
        return $this->bodyMutatingChain();
    }

    public function callSiteBodyMutating(): SymfonyResponse
    {
        return $this->empty()->setContent('surprise');
    }

    public function viaCached(): JsonResponse
    {
        return $this->cached();
    }

    public function viaAssignedNoContent(): JsonResponse
    {
        return $this->assignedNoContent();
    }

    public function viaAccepted(): JsonResponse
    {
        return $this->accepted();
    }

    public function viaAcceptedDirect(): JsonResponse
    {
        return $this->acceptedDirect();
    }

    // endregion

    // region Silently skip (fall through)

    public function viaPositionalMake(): SymfonyResponse
    {
        return $this->positionalMake();
    }

    public function viaOk(): JsonResponse
    {
        return $this->ok(['queued' => true]);
    }

    public function viaFactoryMakeWithBody(): SymfonyResponse
    {
        return $this->factoryMakeWithBody();
    }

    public function viaNonFactoryMake(): SymfonyResponse
    {
        return $this->nonFactoryMake();
    }

    // endregion
}
