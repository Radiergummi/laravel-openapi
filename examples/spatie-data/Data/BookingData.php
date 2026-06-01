<?php

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use DateTimeInterface;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * Output Data class for bookings.
 */
final class BookingData extends Data
{
    public function __construct(
        public string $id,
        public string $flight_id,
        public string $passenger_name,
        public string $seat,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface $created_at,
    ) {}
}
