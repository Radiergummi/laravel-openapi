<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponents;

use OpenApi\Attributes as OA;

/**
 * The reusable `SomeBody` schema the `BodyParam` parameter component references through its schema's
 * `$ref`, exercising the parameter-side transitive pull-in. Signature-only; never invoked.
 */
#[OA\Schema(
    schema: 'SomeBody',
    properties: [
        new OA\Property(property: 'value', type: 'string'),
    ],
    type: 'object',
)]
final class SomeBodySchema {}
