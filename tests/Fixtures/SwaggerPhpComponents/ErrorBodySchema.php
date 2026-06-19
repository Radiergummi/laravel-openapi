<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponents;

use OpenApi\Attributes as OA;

/**
 * The reusable `ErrorBody` schema the `NotFound` response component references transitively.
 * Signature-only; never invoked.
 */
#[OA\Schema(
    schema: 'ErrorBody',
    properties: [
        new OA\Property(property: 'message', type: 'string'),
    ],
    type: 'object',
)]
final class ErrorBodySchema {}
