<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use LogicException;

/**
 * Returns Data classes carrying redundant swagger-php annotations, so the migration removal rule
 * has component schemas (with a source class) to evaluate. Signature-only; never invoked.
 */
class RedundantAnnotationController
{
    public function attribute(): RedundantAttributeData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function docblock(): RedundantDocblockData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function plain(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function essential(): EssentialAttributeData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function refParent(): RefParentData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function divergentRefParent(): DivergentRefParentData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
