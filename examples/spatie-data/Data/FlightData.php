<?php

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use DateTimeInterface;
use Radiergummi\OpenApi\Attributes\Deprecated;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * Output Data class for flights.
 *
 * The SpatieData plugin reflects on this class to derive the response schema.
 * The `$aircraft` property is marked as deprecated via the `#[Deprecated]`
 * attribute to demonstrate that the generator forwards property deprecation
 * into the OpenAPI schema.
 */
final class FlightData extends Data
{
    public function __construct(
        public string $id,
        public string $number,
        public string $origin,
        public string $destination,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface $departs_at,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface $arrives_at,
        public FlightStatus $status,
        public string $aircraft_type,
        /** Legacy free-text aircraft field. */
        #[Deprecated(reason: 'use $aircraft_type instead')]
        public ?string $aircraft = null,
    ) {}
}
