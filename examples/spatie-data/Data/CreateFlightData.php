<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use DateTimeInterface;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * Input Data class for `POST /flights`.
 *
 * Demonstrates Spatie validation attributes — `#[Regex]`, `#[Size]`, `#[Date]` —
 * which the plugin lifts into the request schema constraints.
 */
final class CreateFlightData extends Data
{
    public function __construct(
        #[Regex('/^[A-Z]{2}\d{1,4}$/')]
        public string $number,
        #[Size(3)]
        public string $origin,
        #[Size(3)]
        public string $destination,
        #[Date]
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface $departs_at,
        #[Date]
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface $arrives_at,
        public FlightStatus $status,
        public string $aircraft_type,
    ) {}
}
