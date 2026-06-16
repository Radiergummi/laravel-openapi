<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * Inspects an action and declares any error responses implied by it.
 *
 * Contributors return {@see ErrorDescriptor}s, not full `OA\Response`s: body resolution via the
 * {@see ErrorResponseResolver} chain and `OA\Response` construction stay in the stage that drives
 * the chain.
 *
 * Registered via {@see OpenApiRegistry::addErrorResponseContributor()}.
 */
interface ErrorResponseContributor
{
    /**
     * @return list<ErrorDescriptor>
     */
    public function contribute(ActionDescriptor $descriptor): array;
}
