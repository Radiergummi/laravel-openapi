<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use LogicException;
use OpenApi\Attributes as OA;

/**
 * Carries an operation-level `#[OA\*]` attribute so the operation migration fixer has an
 * attribute-shape annotation to remove. Signature-only; never invoked.
 */
class OperationAttributeController
{
    #[OA\Get(path: '/op-attribute', operationId: 'opAttribute')]
    #[OA\Response(response: 200, description: 'OK')]
    public function redundant(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
