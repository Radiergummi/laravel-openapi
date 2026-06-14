<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\ResponseField;
use Spatie\LaravelData\Data;

/**
 * Carries `x-*` vendor extensions on both a response field and a request field, to prove the
 * `x:` passthrough reaches the emitted component-schema property.
 */
final class VendorExtensionFixtureData extends Data
{
    public function __construct(
        #[ResponseField(description: 'Identifier.', x: ['x-internal-id' => 'abc'])]
        public string $id,
        #[RequestField(x: ['x-ui-widget' => 'slider', 'x-meta' => ['min' => 1]])]
        public int $weight,
    ) {}
}
