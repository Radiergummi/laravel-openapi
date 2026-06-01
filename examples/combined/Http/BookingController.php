<?php

declare(strict_types=1);

namespace Examples\Combined\Http;

use Examples\Combined\Data\BookingData;
use Examples\Combined\Requests\StoreBookingRequest;
use Examples\Combined\Requests\UploadBoardingPassRequest;
use Examples\Shared\Exceptions\FlightOverbookedException;
use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Attributes\Security;
use Radiergummi\OpenApi\Attributes\Tag;
use Spatie\LaravelData\DataCollection;

/**
 * Booking endpoints.
 *
 * Demonstrates the multipart upload path (`uploadBoardingPass`) alongside
 * the standard FormRequest-in / Data-out CRUD operations.
 */
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
    #[PublicEndpoint]
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
     * Validates via {@see StoreBookingRequest} and enforces the 200-seat
     * capacity by raising {@see FlightOverbookedException}.
     *
     * @throws FlightOverbookedException When the flight has no remaining seats.
     * @throws ModelNotFoundException    When the flight does not exist.
     */
    #[Security(['bookings:write'], scheme: 'bearer')]
    #[ResponseAttribute(status: 201, description: 'The created booking', ref: BookingData::class)]
    public function store(StoreBookingRequest $request, string $flight): BookingData
    {
        $model = Flight::query()->findOrFail($flight);

        if ($model->bookings()->count() >= 200) {
            throw new FlightOverbookedException('Flight is fully booked');
        }

        /** @var Booking $booking */
        $booking = $model->bookings()->create($request->validated());

        return BookingData::from($booking);
    }

    /**
     * Upload a boarding pass for a booking.
     *
     * The {@see UploadBoardingPassRequest} carries a `file` rule, which the
     * FormRequest schema resolver maps onto a `multipart/form-data` request
     * body with a binary `image` field.
     *
     * @throws ModelNotFoundException When the booking does not exist.
     */
    #[Security(['bookings:write'], scheme: 'bearer')]
    #[ResponseAttribute(status: 200, description: 'The stored boarding-pass path', schema: ['type' => 'object'])]
    public function uploadBoardingPass(UploadBoardingPassRequest $request, string $booking): JsonResponse
    {
        $model = Booking::query()->findOrFail($booking);

        $path = $request->file('image')?->store("boarding-passes/{$model->id}") ?: '';

        return new JsonResponse(['path' => $path]);
    }

    /**
     * Cancel a booking.
     *
     * @throws ModelNotFoundException When the booking does not exist.
     */
    #[Security(['bookings:write'], scheme: 'bearer')]
    #[ResponseAttribute(status: 204, description: 'The booking was cancelled')]
    public function destroy(string $booking): Response
    {
        $model = Booking::query()->findOrFail($booking);
        $model->delete();

        return response()->noContent();
    }
}
