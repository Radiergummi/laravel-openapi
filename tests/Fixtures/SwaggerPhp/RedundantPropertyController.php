<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use LogicException;

/**
 * Returns Data classes carrying per-member swagger-php annotations, so the per-property removal rule
 * has component schemas (with a source class) to evaluate. Signature-only; never invoked.
 */
class RedundantPropertyController
{
    public function mixed(): RedundantPropertyMixedData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function docblock(): RedundantPropertyDocblockData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function namedSchema(): RedundantPropertyRefParentData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function plainSchema(): RedundantPropertyPlainSchemaData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
