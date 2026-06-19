<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponentMigration;

use LogicException;
use OpenApi\Attributes as OA;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\PlainStructData;

/**
 * References reusable response/parameter components by `$ref`. The 200 response component reproduces
 * exactly what inference derives from the typed return, so the component definition is redundant.
 * Signature-only; never invoked.
 */
class RedundantComponentController
{
    #[OA\Get(path: '/component-redundant', operationId: 'componentRedundant')]
    #[OA\Response(response: 200, ref: '#/components/responses/PlainOk')]
    public function redundant(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    #[OA\Get(path: '/component-essential', operationId: 'componentEssential')]
    #[OA\Response(response: 200, ref: '#/components/responses/DescribedOk')]
    public function essential(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    #[OA\Get(path: '/component-param/{record}', operationId: 'componentParam')]
    #[OA\Parameter(ref: '#/components/parameters/RecordPath')]
    public function param(string $record): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    // Refers to AliasedOk, whose body inference reproduces, but AliasedOk is also $ref-ed by the
    // surviving AliasingResponse component, so the dangling guard must keep AliasedOk.
    #[OA\Get(path: '/component-aliased', operationId: 'componentAliased')]
    #[OA\Response(response: 200, ref: '#/components/responses/AliasedOk')]
    public function aliased(): PlainStructData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}
