<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\ResponseField;
use Spatie\LaravelData\Data;

/**
 * Fixture for verifying that #[ResponseField(conditional: true)] keeps the property in the schema
 * but removes it from required[].
 */
final class ConditionalResponseFieldFixtureData extends Data
{
    public function __construct(
        public string $id,
        #[ResponseField(conditional: true)]
        public string $conditionalField,
        public string $alwaysRequired,
    ) {}
}
