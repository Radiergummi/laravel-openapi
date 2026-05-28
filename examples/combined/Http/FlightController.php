<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined\Http;

use Examples\Combined\Data\FlightData;
use Examples\Combined\Requests\StoreFlightRequest;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Radiergummi\OpenApi\Attributes\Link;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Attributes\ResponseExample;
use Radiergummi\OpenApi\Attributes\Security;
use Radiergummi\OpenApi\Attributes\Tag;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Spatie\LaravelData\DataCollection;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Flight endpoints — the combined flavor headliner.
 *
 * Demonstrates the realistic blend: a typed {@see StoreFlightRequest} validates
 * the input, {@see FlightData} shapes the output, and the index endpoint goes
 * through {@see QueryBuilder} with filter/sort/include attributes.
 */
#[Tag('Flights')]
final class FlightController
{
    /**
     * List flights.
     *
     * Paginated list of flights with `spatie/laravel-query-builder` filters,
     * sorts, and includes. Marked `#[PublicEndpoint]` because anonymous read
     * access is fine for the public catalogue.
     *
     * @return DataCollection<int, FlightData>
     */
    #[AllowedFilter('number', description: 'Exact match on IATA flight designator', type: 'string')]
    #[AllowedFilter('status', type: 'string', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
    #[AllowedFilter('origin', type: 'string', minLength: 3, maxLength: 3)]
    #[AllowedSort(['departs_at', 'number'])]
    #[AllowedInclude(['bookings'])]
    #[PublicEndpoint]
    public function index(): DataCollection
    {
        $paginator = QueryBuilder::for(Flight::class)
            ->allowedFilters(['number', 'status', 'origin'])
            ->allowedSorts(['departs_at', 'number'])
            ->allowedIncludes(['bookings'])
            ->paginate();

        /** @var DataCollection<int, FlightData> $collection */
        $collection = FlightData::collect($paginator->items(), DataCollection::class);

        return $collection;
    }

    /**
     * Show a single flight.
     *
     * Public read endpoint. The response carries a curated example payload
     * loaded from `examples/combined/example_payloads/flight.json` through
     * the `file:` argument of {@see ResponseExample}, demonstrating
     * `ExampleFileLoader` integration.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[Operation(operationId: 'flights.show')]
    #[PublicEndpoint]
    #[ResponseExample(
        status: 200,
        name: 'lh400-from-file',
        summary: 'Curated example loaded from disk',
        description: 'Loaded at spec-generation time by ExampleFileLoader from the JSON file shipped alongside the flavor.',
        file: 'examples/combined/example_payloads/flight.json',
    )]
    public function show(string $flight): FlightData
    {
        return FlightData::from(Flight::query()->findOrFail($flight));
    }

    /**
     * Create a new flight.
     *
     * Validates through {@see StoreFlightRequest} (FormRequest in), returns
     * {@see FlightData} (Data out). The `#[Link]` advertises that the response
     * `id` can be fed straight into the `flights.show` operation.
     */
    #[Security(['flights:write'], scheme: 'bearer')]
    #[Link(
        name: 'self',
        operationId: 'flights.show',
        parameters: ['flight' => '$response.body#/id'],
        description: 'Fetch the just-created flight by its returned id.',
    )]
    #[ResponseAttribute(status: 201, description: 'The created flight', ref: FlightData::class)]
    public function store(StoreFlightRequest $request): FlightData
    {
        return FlightData::from(Flight::query()->create($request->validated()));
    }

    /**
     * Update an existing flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[Security(['flights:write'], scheme: 'bearer')]
    public function update(Request $request, string $flight): FlightData
    {
        $model = Flight::query()->findOrFail($flight);
        $model->update($request->validate([
            'number'        => ['sometimes', 'string'],
            'origin'        => ['sometimes', 'string', 'size:3'],
            'destination'   => ['sometimes', 'string', 'size:3'],
            'departs_at'    => ['sometimes', 'date'],
            'arrives_at'    => ['sometimes', 'date'],
            'status'        => ['sometimes', 'string'],
            'aircraft_type' => ['sometimes', 'string'],
        ]));

        return FlightData::from($model);
    }

    /**
     * Cancel a flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[Security(['flights:write'], scheme: 'bearer')]
    #[ResponseAttribute(status: 204, description: 'The flight was cancelled')]
    public function destroy(string $flight): Response
    {
        $model = Flight::query()->findOrFail($flight);
        $model->delete();

        return response()->noContent();
    }
}
