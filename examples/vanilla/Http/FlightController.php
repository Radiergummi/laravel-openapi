<?php

declare(strict_types=1);

namespace Examples\Vanilla\Http;

use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Attributes\IgnoreLint;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\Tag;

#[Tag('Flights')]
final class FlightController
{
    /**
     * List flights.
     *
     * Returns a paginated list of all known flights.
     */
    #[QueryParam('page', type: 'integer', default: 1, minimum: 1)]
    #[QueryParam('per_page', type: 'integer', default: 25, minimum: 1, maximum: 100)]
    #[Response(status: 200, description: 'A page of flights', schema: ['type' => 'object'])]
    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 25);

        $paginator = Flight::query()
            ->orderBy('departs_at')
            ->paginate(perPage: $perPage, page: $page);

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
    #[Operation(operationId: 'flights.show')]
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
     * Returns the created resource with its server-assigned UUID.
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
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[IgnoreLint('operation.summary-missing', reason: 'Showcasing #[IgnoreLint]: this endpoint deliberately omits the docblock summary so the demo can demonstrate suppression-with-reason.')]
    #[Response(status: 204, description: 'The flight was cancelled')]
    public function destroy(string $flight): JsonResponse
    {
        $model = Flight::query()->findOrFail($flight);
        $model->delete();

        return new JsonResponse(null, 204);
    }
}
