<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponentMigration;

use OpenApi\Attributes as OA;

// Reusable response component definitions in attribute form, stacked on one class so the fixer must
// remove only the targeted block. `PlainOk` reproduces exactly what inference derives for the typed
// 200 return, so it is redundant; `DescribedOk` carries a human description inference cannot derive,
// so it is load-bearing and must be kept. No class docblock: swagger-php would otherwise attach it
// as the first annotation's description and defeat the subsumption. Signature-only; never invoked.
#[OA\Response(
    response: 'PlainOk',
    content: [
        new OA\JsonContent(ref: '#/components/schemas/PlainStructData'),
    ],
)]
#[OA\Response(
    response: 'DescribedOk',
    description: 'A bespoke human description that inference cannot derive.',
    content: [
        new OA\JsonContent(ref: '#/components/schemas/PlainStructData'),
    ],
)]
#[OA\Response(
    response: 'AliasedOk',
    content: [
        new OA\JsonContent(ref: '#/components/schemas/PlainStructData'),
    ],
)]
#[OA\Response(
    response: 'AliasingResponse',
    ref: '#/components/responses/AliasedOk',
)]
#[OA\Response(
    response: 'OrphanOk',
    content: [
        new OA\JsonContent(ref: '#/components/schemas/PlainStructData'),
    ],
)]
final class AttributeComponents {}
