<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\SpatieData\Data\Examples;

use Radiergummi\OpenApi\Core\Attributes\BaseExample;

/**
 * Curated example payload for {@see \Examples\SpatieData\Data\FlightData}.
 *
 * Extends {@see BaseExample} to demonstrate the subclassing pattern — the
 * subclass hard-codes a stable, realistic payload that downstream tooling
 * (Scalar, Swagger UI) renders as a "Try it out" sample.
 */
final readonly class FlightDataExample extends BaseExample
{
    public const string NAME = 'lh400';

    public const string SUMMARY = 'LH400 — Frankfurt to New York';

    public const string DESCRIPTION = 'Lufthansa 400, the daily long-haul to JFK on an A330.';

    /** @var array<string, string> */
    public const array PAYLOAD = [
        'id'            => '0190f3d0-1234-7000-8000-000000000001',
        'number'        => 'LH400',
        'origin'        => 'FRA',
        'destination'   => 'JFK',
        'departs_at'    => '2026-06-01T10:30:00Z',
        'arrives_at'    => '2026-06-01T13:15:00Z',
        'status'        => 'scheduled',
        'aircraft_type' => 'A330',
    ];

    public function __construct()
    {
        parent::__construct(
            name: self::NAME,
            value: self::PAYLOAD,
            summary: self::SUMMARY,
            description: self::DESCRIPTION,
        );
    }
}
