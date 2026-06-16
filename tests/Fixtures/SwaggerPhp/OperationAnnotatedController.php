<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use LogicException;

/**
 * Carries operation-level `@OA` docblock annotations so the operation migration rule has something
 * to evaluate. Signature-only; never invoked.
 */
class OperationAnnotatedController
{
    /**
     * @OA\Get(
     *     path="/op-redundant",
     *
     *     @OA\Response(
     *         response=200,
     *
     *         @OA\JsonContent(ref="#/components/schemas/PlainStructData")
     *     )
     * )
     */
    public function redundant(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @OA\Get(
     *     path="/op-essential",
     *     description="Prose that lives only in the annotation; inference cannot derive it.",
     *
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function essential(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
