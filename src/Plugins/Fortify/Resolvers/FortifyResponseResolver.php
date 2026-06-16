<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Container\Container;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Fortify\Support\FortifyContractTable;
use Radiergummi\OpenApi\Plugins\Fortify\Support\FortifyResponseCustomization;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

use function sprintf;

/**
 * Emits the stock Fortify success response for a matched core-auth route. When the route's
 * response contract has been rebound to a non-Fortify class, emits the status code only (the
 * body is unknowable), never a possibly-wrong stock body.
 *
 * @internal
 */
#[Scoped]
final readonly class FortifyResponseResolver implements PrimaryResponseResolver
{
    private FortifyResponseCustomization $customization;

    public function __construct(Container $container)
    {
        $this->customization = new FortifyResponseCustomization($container);
    }

    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $name = $descriptor->route->getName();

        if ($name === null) {
            return null;
        }

        $entry = FortifyContractTable::for($name);

        if ($entry === null) {
            return null;
        }

        $status = $entry->successStatus;
        $description = HttpFoundationResponse::$statusTexts[$status] ?? sprintf('HTTP %d', $status);

        // Emit the stock body only when we have one AND the governing contract is unmodified.
        $emitBody = $entry->successSchema !== null
            && $this->customization->isStock($entry->responseContract);

        if (!$emitBody) {
            return new OA\Response(['response' => (string) $status, 'description' => $description]);
        }

        return new OA\Response([
            'response' => (string) $status,
            'description' => $description,
            'content' => [MediaType::Json->schema($entry->successSchema)],
        ]);
    }
}
