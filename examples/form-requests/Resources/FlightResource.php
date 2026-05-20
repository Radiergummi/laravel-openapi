<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

/**
 * Output contract for Flight responses.
 *
 * `#[ResourceField]` lives at class level: a JsonResource's keys are
 * `toArray()` entries rather than typed properties, so each key declares its
 * schema with its own attribute.
 *
 * @mixin \Examples\Shared\Models\Flight
 */
#[ResourceField('id', type: 'string', format: 'uuid', description: 'Server-assigned flight identifier.')]
#[ResourceField('number', type: 'string', description: 'IATA-style flight number.', example: 'LH123')]
#[ResourceField('origin', type: 'string', description: 'Three-letter IATA origin code.', example: 'FRA')]
#[ResourceField('destination', type: 'string', description: 'Three-letter IATA destination code.', example: 'JFK')]
#[ResourceField('departs_at', type: 'string', format: 'date-time', description: 'Scheduled departure timestamp.')]
#[ResourceField('arrives_at', type: 'string', format: 'date-time', description: 'Scheduled arrival timestamp.')]
#[ResourceField('status', type: 'string', description: 'Operational status.', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
#[ResourceField('aircraft_type', type: 'string', description: 'Aircraft model / type designator.')]
final class FlightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'number'        => $this->number,
            'origin'        => $this->origin,
            'destination'   => $this->destination,
            'departs_at'    => $this->departs_at->toIso8601String(),
            'arrives_at'    => $this->arrives_at->toIso8601String(),
            'status'        => $this->status,
            'aircraft_type' => $this->aircraft_type,
        ];
    }
}
