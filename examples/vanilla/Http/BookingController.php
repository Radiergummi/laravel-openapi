<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Vanilla\Http;

use Examples\Shared\Exceptions\FlightOverbookedException;
use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Radiergummi\OpenApi\Core\Attributes\Response;
use Radiergummi\OpenApi\Core\Attributes\Tag;

#[Tag('Bookings')]
final class BookingController
{
    /**
     * List bookings for a flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[Response(status: 200, description: 'The flight\'s bookings', schema: [
        'type' => 'array',
        'items' => ['type' => 'object'],
    ])]
    public function index(string $flight): JsonResponse
    {
        $model = Flight::query()->findOrFail($flight);

        return new JsonResponse($model->bookings()->orderBy('seat')->get()->all());
    }

    /**
     * Create a new booking on a flight.
     *
     * @throws \Illuminate\Validation\ValidationException When the request payload is invalid.
     * @throws FlightOverbookedException                  When the flight has no remaining seats.
     * @throws ModelNotFoundException                     When the flight does not exist.
     */
    #[Response(status: 201, description: 'The created booking', schema: ['type' => 'object'])]
    public function store(Request $request, string $flight): JsonResponse
    {
        $model = Flight::query()->findOrFail($flight);

        $data = $request->validate([
            'passenger_name' => ['required', 'string'],
            'seat' => ['required', 'string'],
        ]);

        $booking = $model->bookings()->create($data);

        return new JsonResponse($booking, 201);
    }

    /**
     * Cancel a booking.
     *
     * @throws ModelNotFoundException When the booking does not exist.
     */
    #[Response(status: 204, description: 'The booking was cancelled')]
    public function destroy(string $booking): JsonResponse
    {
        $model = Booking::query()->findOrFail($booking);
        $model->delete();

        return new JsonResponse(null, 204);
    }
}
