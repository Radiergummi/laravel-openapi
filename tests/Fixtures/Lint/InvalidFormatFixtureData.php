<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Lint\Rules\FieldInvalidFormat;
use Spatie\LaravelData\Data;

/**
 * Fixture Data class for testing {@see FieldInvalidFormat}.
 */
final class InvalidFormatFixtureData extends Data
{
    public function __construct(
        /** Not a recognized OAS 3.1 format → finding */
        #[RequestField(format: 'not-a-format')]
        public string $invalidFormat,

        /** A valid OAS 3.1 format → no finding */
        #[RequestField(format: 'date-time')]
        public string $validFormat,

        /** No format specified → no finding */
        #[RequestField(description: 'No format')]
        public string $noFormat,

        /** No RequestField attribute → no finding */
        public string $noAttribute,
    ) {}
}
