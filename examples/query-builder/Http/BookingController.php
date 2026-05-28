<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\QueryBuilder\Http;

use Examples\Shared\Exceptions\FlightOverbookedException;
use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\Tag;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

#[Tag('Bookings')]
final class BookingController
{
    /**
     * List bookings for a flight.
     *
     * Filterable by passenger name and sortable by creation timestamp via
     * `spatie/laravel-query-builder`.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[AllowedFilter('passenger_name', type: 'string')]
    #[AllowedSort(['created_at'])]
    #[Response(status: 200, description: 'The flight\'s bookings', schema: [
        'type' => 'array',
        'items' => ['type' => 'object'],
    ])]
    public function index(string $flight): JsonResponse
    {
        Flight::query()->findOrFail($flight);

        $bookings = QueryBuilder::for(Booking::query()->where('flight_id', $flight))
            ->allowedFilters(['passenger_name'])
            ->allowedSorts(['created_at'])
            ->get();

        return new JsonResponse($bookings->all());
    }

    /**
     * Create a new booking on a flight.
     *
     * @throws FlightOverbookedException When the flight has no remaining seats.
     * @throws ModelNotFoundException    When the flight does not exist.
     * @throws ValidationException       When the request payload is invalid.
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
