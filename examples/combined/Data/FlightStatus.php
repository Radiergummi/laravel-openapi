<?php

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
