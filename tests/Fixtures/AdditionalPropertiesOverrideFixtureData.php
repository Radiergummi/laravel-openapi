<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\ResponseField;
use Spatie\LaravelData\Data;

/**
 * Exercises the field-level `additionalProperties:` override against #334's map inference.
 * Both properties are `array<string, AddressFixtureData>` (inferred to
 * `additionalProperties: {$ref: AddressFixtureData}`); the attribute must win, running last.
 */
final class AdditionalPropertiesOverrideFixtureData extends Data
{
    public function __construct(
        /** @var array<string, AddressFixtureData> */
        #[ResponseField(additionalProperties: false)]
        public array $closedMap,

        /** @var array<string, AddressFixtureData> */
        #[ResponseField(additionalProperties: 'string')]
        public array $retypedMap,
    ) {}
}
