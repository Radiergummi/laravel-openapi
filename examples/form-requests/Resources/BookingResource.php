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
 * Output contract for Booking responses.
 *
 * @mixin \Examples\Shared\Models\Booking
 */
#[ResourceField('id', description: 'Server-assigned booking identifier.', type: 'string', format: 'uuid')]
#[ResourceField('flight_id', description: 'Identifier of the flight this booking belongs to.', type: 'string', format: 'uuid')]
#[ResourceField('passenger_name', description: 'Full name of the passenger.', type: 'string', minLength: 1, maxLength: 200)]
#[ResourceField('seat', description: 'Seat assignment, e.g. 12A.', type: 'string', pattern: '^\\d{1,3}[A-Z]$')]
#[ResourceField('created_at', description: 'Booking creation timestamp.', type: 'string', format: 'date-time')]
final class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'flight_id'      => $this->flight_id,
            'passenger_name' => $this->passenger_name,
            'seat'           => $this->seat,
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
