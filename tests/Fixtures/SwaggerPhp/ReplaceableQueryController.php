<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use LogicException;
use OpenApi\Attributes as OA;

/**
 * Carries a query parameter the replacement rule rewrites as #[QueryParam].
 * Signature-only; never invoked.
 */
class ReplaceableQueryController
{
    #[OA\Get(path: '/replaceable-query', operationId: 'replaceableQuery')]
    #[OA\Parameter(name: 'q', in: 'query', required: true, description: 'Free-text search.')]
    public function index(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
