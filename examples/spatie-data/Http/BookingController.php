<?php

declare(strict_types=1);

namespace Examples\SpatieData\Http;

use Examples\Shared\Exceptions\FlightOverbookedException;
use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;
use Examples\SpatieData\Data\BookingData;
use Examples\SpatieData\Data\CreateBookingData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Radiergummi\OpenApi\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Attributes\Tag;
use Spatie\LaravelData\DataCollection;

#[Tag('Bookings')]
final class BookingController
{
    /**
     * List bookings for a flight.
     *
     * @return DataCollection<int, BookingData>
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    public function index(string $flight): DataCollection
    {
        $bookings = Flight::query()->findOrFail($flight)->bookings()->orderBy('seat')->get();

        /** @var DataCollection<int, BookingData> $collection */
        $collection = BookingData::collect($bookings, DataCollection::class);

        return $collection;
    }

    /**
     * Create a new booking on a flight.
     *
     * Validates via {@see CreateBookingData} and enforces the 200-seat
     * capacity by raising {@see FlightOverbookedException} — the `@throws`
     * annotation drives the 409 response through the exception-response map.
     *
     * @throws FlightOverbookedException When the flight has no remaining seats.
     * @throws ModelNotFoundException    When the flight does not exist.
     */
    #[ResponseAttribute(status: 201, description: 'The created booking', ref: BookingData::class)]
    public function store(CreateBookingData $data, string $flight): BookingData
    {
        $model = Flight::query()->findOrFail($flight);

        if ($model->bookings()->count() >= 200) {
            throw new FlightOverbookedException('Flight is fully booked');
        }

        /** @var Booking $booking */
        $booking = $model->bookings()->create($data->toArray());

        return BookingData::from($booking);
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
