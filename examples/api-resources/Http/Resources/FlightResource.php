<?php

declare(strict_types=1);

namespace Examples\ApiResources\Http\Resources;

use Examples\Shared\Models\Flight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

/**
 * Output contract for Flight responses.
 *
 * A JsonResource's keys come from `toArray()`, not from typed properties, so
 * `#[ResourceField]` is declared at class level — one entry per key.
 *
 * @mixin Flight
 */
#[ResourceField('id', description: 'Server-assigned flight identifier.', type: 'string', format: 'uuid')]
#[ResourceField('number', description: 'IATA-style flight number.', example: 'LH123', type: 'string')]
#[ResourceField('origin', description: 'Three-letter IATA origin code.', example: 'FRA', type: 'string')]
#[ResourceField('destination', description: 'Three-letter IATA destination code.', example: 'JFK', type: 'string')]
#[ResourceField('departs_at', description: 'Scheduled departure timestamp.', type: 'string', format: 'date-time')]
#[ResourceField('arrives_at', description: 'Scheduled arrival timestamp.', type: 'string', format: 'date-time')]
#[ResourceField('status', description: 'Operational status.', type: 'string', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
#[ResourceField('aircraft_type', description: 'Aircraft model / type designator.', type: 'string')]
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
