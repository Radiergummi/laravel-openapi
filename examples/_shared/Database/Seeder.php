<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared\Database;

use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;

final class Seeder
{
    public static function run(): void
    {
        $lh400 = Flight::create([
            'number' => 'LH400',
            'origin' => 'FRA',
            'destination' => 'JFK',
            'departs_at' => '2026-06-01T10:30:00Z',
            'arrives_at' => '2026-06-01T13:15:00Z',
            'status' => 'scheduled',
            'aircraft_type' => 'A330',
        ]);

        Flight::create([
            'number' => 'BA286',
            'origin' => 'SFO',
            'destination' => 'LHR',
            'departs_at' => '2026-06-02T20:00:00Z',
            'arrives_at' => '2026-06-03T14:45:00Z',
            'status' => 'scheduled',
            'aircraft_type' => 'B777',
        ]);

        Flight::create([
            'number' => 'AF11',
            'origin' => 'CDG',
            'destination' => 'JFK',
            'departs_at' => '2026-05-25T08:30:00Z',
            'arrives_at' => '2026-05-25T11:00:00Z',
            'status' => 'departed',
            'aircraft_type' => 'A380',
        ]);

        Booking::create(['flight_id' => $lh400->id, 'passenger_name' => 'Ada Lovelace', 'seat' => '3A']);
        Booking::create(['flight_id' => $lh400->id, 'passenger_name' => 'Alan Turing', 'seat' => '3B']);
    }
}
