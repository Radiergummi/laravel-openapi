<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined\Data;

use DateTimeInterface;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * Output Data class for flights in the combined flavor.
 *
 * Reused as the response shape for every flight endpoint — including the ones
 * that take a {@see \Examples\Combined\Requests\StoreFlightRequest} on input,
 * demonstrating the FormRequest-in / Data-out blend.
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
    ) {}
}
