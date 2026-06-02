<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\ResponseField;
use Spatie\LaravelData\Data;

/**
 * Response-side Data fixture: the `id` property carries a #[ResponseField] whose description and
 * readOnly flag the generator must surface on the component schema property.
 */
final class ResponseFieldFixtureData extends Data
{
    public function __construct(
        #[ResponseField(description: 'Server-assigned identifier.', readOnly: true)]
        public string $id,
        public string $name,
    ) {}
}
