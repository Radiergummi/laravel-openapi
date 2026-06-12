<?php

declare(strict_types=1);

namespace Examples\ApiResources\Http;

use Examples\ApiResources\Http\Resources\BookingResource;
use Examples\Shared\Models\Booking;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Radiergummi\OpenApi\Attributes\Tag;

#[Tag('Bookings')]
final class BookingController
{
    /**
     * List bookings.
     *
     * The signature only says `AnonymousResourceCollection` — the generator reads
     * the `BookingResource::collection(...)` return expression to resolve the item
     * resource, and the `paginate()` call to pick the `{data, links, meta}`
     * envelope. No `#[ResponseResource]` needed.
     */
    public function index(): AnonymousResourceCollection
    {
        return BookingResource::collection(
            Booking::query()->latest()->paginate(),
        );
    }

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
