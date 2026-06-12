<?php

declare(strict_types=1);

namespace Examples\ApiResources\Http;

use Examples\ApiResources\Http\Resources\BookingResource;
use Examples\Shared\Models\Booking;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Radiergummi\OpenApi\Attributes\Tag;

#[Tag('Bookings')]
final class BookingController
{
    /**
     * Show a single booking.
     *
     * The response schema is inferred entirely from `BookingResource::toArray()` —
     * the resource declares no `#[ResourceField]` attributes.
     *
     * @throws ModelNotFoundException When the booking does not exist.
     */
    public function show(string $booking): BookingResource
    {
        return new BookingResource(Booking::query()->findOrFail($booking));
    }
}
