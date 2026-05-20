<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined\Data;

/**
 * Operational status of a flight.
 *
 * Backed enums are reflected automatically by the SpatieData plugin and emerge
 * in the schema as a string property with an `enum` constraint.
 */
enum FlightStatus: string
{
    case Scheduled = 'scheduled';
    case Boarding  = 'boarding';
    case Departed  = 'departed';
    case Arrived   = 'arrived';
    case Cancelled = 'cancelled';
}
