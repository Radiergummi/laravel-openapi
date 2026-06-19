<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponents;

use OpenApi\Attributes as OA;

/**
 * Declares reusable response/parameter component definitions the harvester should pick up. The
 * `NotFound` response references the `ErrorBody` schema (declared on {@see ErrorBodySchema}), so the
 * harvester must pull that schema in transitively. `BodyParam` does the same on the parameter side:
 * its schema references `SomeBody` (declared on {@see SomeBodySchema}).
 *
 * `AliasNotFound` and `AliasParam` are ref-only *usage* entries (a component pointing at another
 * component), not definitions; swagger-php still lands them in `components.responses`/`.parameters`
 * with both a name and a `ref`, so the scanner must skip them rather than index them as definitions.
 * Signature-only; never invoked.
 */
#[OA\Response(
    response: 'NotFound',
    description: 'The resource was not found.',
    content: [
        new OA\JsonContent(ref: '#/components/schemas/ErrorBody'),
    ],
)]
#[OA\Response(
    response: 'AliasNotFound',
    ref: '#/components/responses/NotFound',
)]
#[OA\Parameter(
    parameter: 'PageParam',
    name: 'page',
    in: 'query',
    schema: new OA\Schema(type: 'integer'),
)]
#[OA\Parameter(
    parameter: 'BodyParam',
    name: 'body',
    in: 'query',
    schema: new OA\Schema(ref: '#/components/schemas/SomeBody'),
)]
#[OA\Parameter(
    parameter: 'AliasParam',
    ref: '#/components/parameters/PageParam',
)]
final class ComponentDefinitions {}
