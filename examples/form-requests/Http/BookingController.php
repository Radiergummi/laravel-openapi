<?php

declare(strict_types=1);

namespace Examples\FormRequests\Http;

use Examples\FormRequests\Requests\StoreBookingRequest;
use Examples\FormRequests\Resources\BookingResource;
use Examples\Shared\Exceptions\FlightOverbookedException;
use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Radiergummi\OpenApi\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Attributes\Tag;

#[Tag('Bookings')]
final class BookingController
{
    /**
     * List bookings for a flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[ResponseResource(BookingResource::class, collection: true)]
    public function index(string $flight): AnonymousResourceCollection
    {
        $model = Flight::query()->findOrFail($flight);

        return BookingResource::collection($model->bookings()->orderBy('seat')->get());
    }

    /**
     * Create a new booking on a flight.
     *
     * Validates via StoreBookingRequest and enforces the 200-seat capacity
     * by raising FlightOverbookedException — the @throws annotation drives
     * the 409 response in the spec via the exception-response map configured
     * in OpenApiConfig.
     *
     * @throws FlightOverbookedException When the flight has no remaining seats.
     * @throws ModelNotFoundException    When the flight does not exist.
     */
    #[ResponseResource(BookingResource::class)]
    #[ResponseAttribute(status: 201, description: 'The created booking', ref: BookingResource::class)]
    public function store(StoreBookingRequest $request, string $flight): BookingResource
    {
        $model = Flight::query()->findOrFail($flight);

        if ($model->bookings()->count() >= 200) {
            throw new FlightOverbookedException('Flight is fully booked');
        }

        /** @var Booking $booking */
        $booking = $model->bookings()->create($request->validated());

        return new BookingResource($booking);
    }

    /**
     * Cancel a booking.
     *
     * @throws ModelNotFoundException When the booking does not exist.
     */
    #[ResponseAttribute(status: 204, description: 'The booking was cancelled')]
    public function destroy(string $booking): Response
    {
        $model = Booking::query()->findOrFail($booking);
        $model->delete();

        return response()->noContent();
    }
}
