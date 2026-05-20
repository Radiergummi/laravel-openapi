<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Http;

use Examples\FormRequests\Requests\StoreFlightRequest;
use Examples\FormRequests\Requests\UpdateFlightRequest;
use Examples\FormRequests\Resources\FlightResource;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Radiergummi\OpenApi\Core\Attributes\Header;
use Radiergummi\OpenApi\Core\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Core\Attributes\ResponseHeader;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Attributes\Tag;

#[Tag('Flights')]
#[Header(name: 'X-Request-Id', description: 'Optional client-supplied correlation id echoed back on every response.')]
final class FlightController
{
    /**
     * List flights.
     *
     * Returns a paginated collection of all known flights wrapped in the
     * standard JsonResource `{data, links, meta}` envelope.
     */
    #[ResponseResource(FlightResource::class, collection: true)]
    public function index(): AnonymousResourceCollection
    {
        return FlightResource::collection(
            Flight::query()->orderBy('departs_at')->paginate(),
        );
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[ResponseResource(FlightResource::class)]
    public function show(string $flight): FlightResource
    {
        return new FlightResource(Flight::query()->findOrFail($flight));
    }

    /**
     * Create a new flight.
     *
     * Persists a new flight record from a typed FormRequest and returns the
     * created resource. The 201 status is documented via #[Response] because
     * the auto-derived response is always 200.
     */
    #[ResponseResource(FlightResource::class)]
    #[ResponseAttribute(status: 201, description: 'The created flight', ref: FlightResource::class)]
    #[ResponseHeader(
        name: 'Location',
        status: 201,
        description: 'URL of the created flight',
        type: 'string',
        format: 'uri',
    )]
    public function store(StoreFlightRequest $request): FlightResource
    {
        $flight = Flight::query()->create($request->validated());

        return (new FlightResource($flight))->additional([
            '_location' => "/api/flights/{$flight->id}",
        ]);
    }

    /**
     * Update an existing flight.
     *
     * Accepts a partial payload validated by UpdateFlightRequest.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[ResponseResource(FlightResource::class)]
    public function update(UpdateFlightRequest $request, string $flight): FlightResource
    {
        $model = Flight::query()->findOrFail($flight);
        $model->update($request->validated());

        return new FlightResource($model);
    }

    /**
     * Cancel a flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[ResponseAttribute(status: 204, description: 'The flight was cancelled')]
    public function destroy(string $flight): Response
    {
        $model = Flight::query()->findOrFail($flight);
        $model->delete();

        return response()->noContent();
    }
}
