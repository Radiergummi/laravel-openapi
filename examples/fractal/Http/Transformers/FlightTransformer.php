<?php

declare(strict_types=1);

namespace Examples\Fractal\Http\Transformers;

use Examples\Shared\Models\Flight;
use League\Fractal\TransformerAbstract;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;

/**
 * Output contract for Flight responses.
 *
 * Each field is declared at class level via `#[TransformerField]` to carry
 * descriptions, formats, and enums the `transform()` literal cannot express.
 * Declared fields always win over what the generator infers from the literal
 * (see `BookingTransformer` for the attribute-free, inference-only variant).
 */
#[TransformerField('id', description: 'Server-assigned flight identifier.', type: 'string', format: 'uuid')]
#[TransformerField('number', description: 'IATA-style flight number.', example: 'LH123', type: 'string')]
#[TransformerField('origin', description: 'Three-letter IATA origin code.', example: 'FRA', type: 'string')]
#[TransformerField('destination', description: 'Three-letter IATA destination code.', example: 'JFK', type: 'string')]
#[TransformerField('departs_at', description: 'Scheduled departure timestamp.', type: 'string', format: 'date-time')]
#[TransformerField('arrives_at', description: 'Scheduled arrival timestamp.', type: 'string', format: 'date-time')]
#[TransformerField('status', description: 'Operational status.', type: 'string', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
#[TransformerField('aircraft_type', description: 'Aircraft model / type designator.', type: 'string')]
final class FlightTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Flight $flight): array
    {
        return [
            'id'            => $flight->id,
            'number'        => $flight->number,
            'origin'        => $flight->origin,
            'destination'   => $flight->destination,
            'departs_at'    => $flight->departs_at->toIso8601String(),
            'arrives_at'    => $flight->arrives_at->toIso8601String(),
            'status'        => $flight->status,
            'aircraft_type' => $flight->aircraft_type,
        ];
    }
}
