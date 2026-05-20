<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Input Data class for `POST /flights/{flight}/bookings`.
 */
final class CreateBookingData extends Data
{
    public function __construct(
        #[Max(200)]
        public string $passenger_name,
        #[Regex('/^\d{1,3}[A-Z]$/')]
        public string $seat,
    ) {}
}
