<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Http\RedirectResponse;
use OpenApi\Annotations as OA;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\ReturnContainer;
use Radiergummi\OpenApi\Support\Routing\ReturnShape;
use Radiergummi\OpenApi\Support\Routing\ReturnShapeResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\TypeInfo\Type\ObjectType;

use function is_a;
use function sprintf;

/**
 * Reads a framework HTTP response type from a controller's declared return, where the return type
 * alone carries the whole response contract (Tier-0, no body parsing):
 *
 * - `RedirectResponse` → `302 Found` with a `Location` response header. The status is explicit: a
 *   redirect is 3xx by definition, so a route convention must not overwrite it.
 * - `StreamedResponse` / `BinaryFileResponse` → a binary `200` (`application/octet-stream`). Only
 *   the media type is type-derived; the status stays open to the route convention.
 *
 * App subclasses match (`is_a(..., allow_string: true)`). A union return carries more than one
 * contract, so it is refused and left to degrade. Runs ahead of the baseline
 * {@see \Radiergummi\OpenApi\Support\Extraction\TypedReturnResponseResolver}, which would otherwise
 * try to build a POPO schema from these classes' public properties.
 *
 * @internal
 */
final readonly class HttpResponseTypeResolver implements PrimaryResponseResolver
{
    public function __construct(
        private ReturnShapeResolver $shapeResolver,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?PrimaryResponse
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return null;
        }

        $shape = $this->shapeResolver->describe($reflector);

        if ($shape->unionMembers !== []) {
            $this->noteRefusedUnion($descriptor, $shape);

            return null;
        }

        if ($shape->container !== ReturnContainer::Single || !$shape->itemType instanceof ObjectType) {
            return null;
        }

        $className = $shape->itemType->getClassName();

        // Deliberately bound to Illuminate's RedirectResponse, the type Laravel controllers declare;
        // a bare Symfony RedirectResponse return is left to degrade like any other unknown class.
        if (is_a($className, RedirectResponse::class, allow_string: true)) {
            // The status is read from the type itself (a redirect response is 3xx by definition), so
            // the route convention must not rewrite it to its 200/201/204 and drop the Location.
            return PrimaryResponse::of($this->redirectResponse(), statusIsExplicit: true);
        }

        if (
            is_a($className, StreamedResponse::class, allow_string: true)
            || is_a($className, BinaryFileResponse::class, allow_string: true)
        ) {
            // Only the media type is type-derived here: a streamed response carries any status, so
            // the 200 is a fallback the route convention may still promote (a `store()` streaming
            // its result is a 201). A 204 convention keeps yielding to this content-bearing body.
            return PrimaryResponse::of($this->binaryResponse());
        }

        return null;
    }

    private function redirectResponse(): OA\Response
    {
        return new OA\Response([
            'response' => '302',
            'description' => 'Found',
            'headers' => [
                new OA\Header([
                    'header' => 'Location',
                    'description' => 'The URL to redirect to.',
                    'schema' => new OA\Schema(['type' => 'string', 'format' => 'uri']),
                ]),
            ],
        ]);
    }

    private function binaryResponse(): OA\Response
    {
        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [
                MediaType::OctetStream->schema(new OA\Schema(['type' => 'string', 'format' => 'binary'])),
            ],
        ]);
    }

    /**
     * Logs a degradation note when a union return includes one of the framework HTTP response types
     * this resolver reads: the multiple contracts cannot be expressed as a single primary response,
     * so the operation falls back to the synthetic 200.
     */
    private function noteRefusedUnion(ActionDescriptor $descriptor, ReturnShape $shape): void
    {
        $includesHttpResponseType = false;

        foreach ($shape->unionMembers as $member) {
            if (!$member instanceof ObjectType) {
                continue;
            }

            $memberClass = $member->getClassName();

            if (
                is_a($memberClass, RedirectResponse::class, allow_string: true)
                || is_a($memberClass, StreamedResponse::class, allow_string: true)
                || is_a($memberClass, BinaryFileResponse::class, allow_string: true)
            ) {
                $includesHttpResponseType = true;

                break;
            }
        }

        if (!$includesHttpResponseType) {
            return;
        }

        $this->logger->debug(sprintf(
            'Route %s returns a union including a framework HTTP response type; '
            . 'a single response contract cannot be derived, so the response degrades to a bare 200.',
            $descriptor->route->uri(),
        ));
    }
}
