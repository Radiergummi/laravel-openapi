<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponents;

use OpenApi\Attributes as OA;

/**
 * Declares reusable response/parameter component definitions the harvester should pick up. The
 * `NotFound` response references the `ErrorBody` schema (declared on {@see ErrorBodySchema}), so the
 * harvester must pull that schema in transitively. Signature-only; never invoked.
 */
#[OA\Response(
    response: 'NotFound',
    description: 'The resource was not found.',
    content: [
        new OA\JsonContent(ref: '#/components/schemas/ErrorBody'),
    ],
)]
#[OA\Parameter(
    parameter: 'PageParam',
    name: 'page',
    in: 'query',
    schema: new OA\Schema(type: 'integer'),
)]
final class ComponentDefinitions {}
