<?php

declare(strict_types=1);

namespace Examples\ApiResources\Http\Resources;

use Examples\Shared\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output contract for Booking responses — no `#[ResourceField]` attributes at all.
 *
 * The generator reads the `toArray()` array literal directly: `$this->field`
 * references type themselves from the wrapped model (the `@mixin` below), and the
 * nested `FlightResource` becomes a `$ref` marked optional by its `whenLoaded()`
 * wrapper.
 *
 * @mixin Booking
 */
final class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'passenger_name' => $this->passenger_name,
            'seat'           => $this->seat,
            'flight'         => new FlightResource($this->whenLoaded('flight')),
            'created_at'     => $this->created_at,
        ];
    }
}
