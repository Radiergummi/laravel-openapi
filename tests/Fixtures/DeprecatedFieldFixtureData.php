<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Fixture Data class for OAPI-031: deprecated property detection via PHPDoc.
 */
final class DeprecatedFieldFixtureData extends Data
{
    public function __construct(
        /** Active field — must NOT have deprecated: true in the schema. */
        public string $active,
        /**
         * @deprecated Use active instead.
         */
        public string $legacy = 'old',
    ) {}
}
