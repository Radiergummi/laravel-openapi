<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponents;

use LogicException;
use OpenApi\Attributes as OA;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\PlainStructData;

/**
 * An operation referencing the reusable response/parameter components by `$ref`, plus one referencing
 * components that do not exist (the genuinely-dangling case). `BodyParam` exercises the parameter-side
 * transitive schema pull-in. Signature-only; never invoked.
 */
class ComponentRefController
{
    #[OA\Get(path: '/widgets', operationId: 'listWidgets')]
    #[OA\Parameter(ref: '#/components/parameters/PageParam')]
    #[OA\Parameter(ref: '#/components/parameters/BodyParam')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function index(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    #[OA\Get(path: '/gadgets', operationId: 'listGadgets')]
    #[OA\Parameter(ref: '#/components/parameters/Missing')]
    #[OA\Response(response: 404, ref: '#/components/responses/Missing')]
    public function missing(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
