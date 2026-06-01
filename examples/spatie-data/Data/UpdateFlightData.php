<?php

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use DateTimeInterface;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Input Data class for `PATCH /flights/{flight}`.
 *
 * Every property unions in {@see Optional}: when omitted from the payload, the
 * field is dropped from the model update — letting clients send partial
 * updates without resetting unspecified fields.
 */
final class UpdateFlightData extends Data
{
    public function __construct(
        #[Regex('/^[A-Z]{2}\d{1,4}$/')]
        public string|Optional $number,
        #[Size(3)]
        public string|Optional $origin,
        #[Size(3)]
        public string|Optional $destination,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface|Optional $departs_at,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface|Optional $arrives_at,
        public FlightStatus|Optional $status,
        public string|Optional $aircraft_type,
    ) {}
}
