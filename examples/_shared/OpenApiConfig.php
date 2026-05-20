<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared;

use Examples\Shared\Exceptions\FlightOverbookedException;

/**
 * Config overrides applied by every flavor. Each flavor's ServiceProvider calls
 * {@see apply()} with its own flavor label; the rest of the config (tags,
 * exception mappings) is shared.
 */
final class OpenApiConfig
{
    public static function apply(string $flavorLabel): void
    {
        config()->set('openapi.info', [
            'title'       => "Flights API – {$flavorLabel} Example",
            'version'     => '1.0.0',
            'description' => "Sample API used to demonstrate radiergummi/laravel-openapi's {$flavorLabel} integration.",
        ]);

        config()->set('openapi.tags', [
            'Flights'  => ['description' => 'Operations on flights'],
            'Bookings' => ['description' => 'Operations on flight bookings'],
        ]);

        // Domain exceptions used across flavors. The generator looks these up by short name
        // when an `@throws` annotation references them, so importing the exception in the
        // controller is enough.
        config()->set('openapi.exception_responses', array_merge(
            (array) config('openapi.exception_responses', []),
            [
                FlightOverbookedException::class => [
                    'status'      => 409,
                    'description' => 'Flight is fully booked',
                ],
            ],
        ));
    }
}
