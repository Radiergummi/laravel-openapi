<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use LogicException;

/**
 * Returns Data classes carrying replaceable swagger-php annotations, so the replacement rule has
 * component schemas (with a source class) to evaluate. Signature-only; never invoked.
 */
class ReplaceableAttributeController
{
    public function attribute(): ReplaceableAttributeData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function docblock(): ReplaceableDocblockData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function enum(): EnumPropertyData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
