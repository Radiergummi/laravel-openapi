<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Exercises map (string-keyed array) inference on the SpatieData typed-property path.
 * String keys become `additionalProperties`; int keys, lists and bare arrays stay plain arrays.
 */
final class MapPropertiesFixtureData extends Data
{
    public function __construct(
        /** @var array<string, AddressFixtureData> */
        public array $addressMap,

        /** @var array<string, string> */
        public array $scalarMap,

        /** @var array<string, mixed> */
        public array $opaqueMap,

        /** @var array<string, array<string, AddressFixtureData>> */
        public array $nestedMap,

        /** @var array<string, ?AddressFixtureData> */
        public array $nullableValueMap,

        /** @var array<string, int|string> */
        public array $unionValueMap,

        /** @var list<AddressFixtureData> */
        public array $addressList,

        /** @var array<int, AddressFixtureData> */
        public array $indexedList,
        public array $bareArray,
    ) {}
}
