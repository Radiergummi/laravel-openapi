<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponents;

use LogicException;
use OpenApi\Attributes as OA;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\PlainStructData;

/**
 * An operation referencing the reusable response/parameter components by `$ref`, plus one referencing
 * a component that does not exist (the genuinely-dangling case). Signature-only; never invoked.
 */
class ComponentRefController
{
    #[OA\Get(path: '/widgets', operationId: 'listWidgets')]
    #[OA\Parameter(ref: '#/components/parameters/PageParam')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function index(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    #[OA\Get(path: '/gadgets', operationId: 'listGadgets')]
    #[OA\Response(response: 404, ref: '#/components/responses/Missing')]
    public function missing(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
