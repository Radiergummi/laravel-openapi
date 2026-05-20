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

        // Suppress lint rules that produce false positives against the example surface.
        //   - `response.description-missing` fires for $ref'd error responses (e.g. `404 -> $ref:
        //     '#/components/responses/NotFound'`) because the rule does not follow the $ref into
        //     `components.responses`, where the description actually lives. This is a Core gap;
        //     for the showcase it would be misleading noise.
        //   - `response.no-error` is disabled because:
        //       - some demo GET endpoints intentionally model "always succeeds or
        //         404s" — no 4xx surface beyond a single `$ref` is appropriate; and
        //       - the package itself registers `/api/openapi.yaml` which the
        //         examples cannot annotate (it is owned by the OpenApiServiceProvider,
        //         not by example code).
        config()->set('openapi.lint.disabled_rules', array_values(array_unique(array_merge(
            (array) config('openapi.lint.disabled_rules', []),
            ['response.description-missing', 'response.no-error'],
        ))));

        config()->set('openapi.tags', [
            $flavorLabel => ['description' => "Example flavor: {$flavorLabel}"],
            'Flights'    => ['description' => 'Operations on flights'],
            'Bookings'   => ['description' => 'Operations on flight bookings'],
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
