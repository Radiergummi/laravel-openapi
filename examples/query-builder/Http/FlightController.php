<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\QueryBuilder\Http;

use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\Tag;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Spatie\QueryBuilder\AllowedFilter as RuntimeAllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

#[Tag('Flights')]
final class FlightController
{
    /**
     * List flights.
     *
     * Returns a paginated list of flights, filterable and sortable through the
     * `spatie/laravel-query-builder` conventions documented by the
     * `#[AllowedFilter]`, `#[AllowedSort]` and `#[AllowedInclude]` attributes.
     */
    #[AllowedFilter('number', description: 'Exact match on IATA flight designator', type: 'string')]
    #[AllowedFilter('status', type: 'string', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
    #[AllowedFilter('origin', type: 'string', minLength: 3, maxLength: 3)]
    #[AllowedFilter('departs_after', description: 'Only flights departing at or after this timestamp', type: 'string', format: 'date-time', nullable: true)]
    #[AllowedSort(['departs_at', 'number'])]
    #[AllowedInclude(['bookings'])]
    #[Response(status: 200, description: 'A page of flights', schema: ['type' => 'object'])]
    public function index(): JsonResponse
    {
        $paginator = QueryBuilder::for(Flight::class)
            ->allowedFilters([
                'number',
                'status',
                'origin',
                RuntimeAllowedFilter::callback(
                    'departs_after',
                    static fn(Builder $query, mixed $value): Builder => $query->where('departs_at', '>=', $value),
                ),
            ])
            ->allowedSorts(['departs_at', 'number'])
            ->allowedIncludes(['bookings'])
            ->paginate();

        return new JsonResponse([
            'data' => $paginator->items(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[Response(status: 200, description: 'The flight', schema: ['type' => 'object'])]
    public function show(string $flight): JsonResponse
    {
        $model = Flight::query()->findOrFail($flight);

        return new JsonResponse($model);
    }

    /**
     * Create a new flight.
     *
     * Persists a new flight record with the supplied schedule and status.
     *
     * @throws ValidationException When the request payload is invalid.
     */
    #[Response(status: 201, description: 'The created flight', schema: ['type' => 'object'])]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string'],
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
            'departs_at' => ['required', 'date'],
            'arrives_at' => ['required', 'date'],
            'status' => ['required', 'string'],
            'aircraft_type' => ['required', 'string'],
        ]);

        $flight = Flight::create($data);

        return new JsonResponse($flight, 201);
    }

    /**
     * Update an existing flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     * @throws ValidationException    When the request payload is invalid.
     */
    #[Response(status: 200, description: 'The updated flight', schema: ['type' => 'object'])]
    public function update(Request $request, string $flight): JsonResponse
    {
        $model = Flight::query()->findOrFail($flight);

        $data = $request->validate([
            'number' => ['sometimes', 'string'],
            'origin' => ['sometimes', 'string', 'size:3'],
            'destination' => ['sometimes', 'string', 'size:3'],
            'departs_at' => ['sometimes', 'date'],
            'arrives_at' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string'],
            'aircraft_type' => ['sometimes', 'string'],
        ]);

        $model->update($data);

        return new JsonResponse($model);
    }

    /**
     * Cancel a flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[Response(status: 204, description: 'The flight was cancelled')]
    public function destroy(string $flight): JsonResponse
    {
        $model = Flight::query()->findOrFail($flight);
        $model->delete();

        return new JsonResponse(null, 204);
    }
}
