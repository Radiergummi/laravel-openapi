<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\SpatieData\Http;

use Examples\Shared\Models\Flight;
use Examples\SpatieData\Data\CreateFlightData;
use Examples\SpatieData\Data\Examples\FlightDataExample;
use Examples\SpatieData\Data\FlightData;
use Examples\SpatieData\Data\UpdateFlightData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Radiergummi\OpenApi\Attributes\ExternalDocs;
use Radiergummi\OpenApi\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Attributes\ResponseExample;
use Radiergummi\OpenApi\Attributes\Tag;
use Spatie\LaravelData\DataCollection;

#[Tag('Flights')]
#[ExternalDocs(url: 'https://example.com/docs/flights', description: 'Public API reference')]
final class FlightController
{
    /**
     * List flights.
     *
     * Returns a page of flights as a Spatie `DataCollection` of {@see FlightData}.
     *
     * @return DataCollection<int, FlightData>
     */
    public function index(): DataCollection
    {
        $flights = Flight::query()->orderBy('departs_at')->paginate();

        /** @var DataCollection<int, FlightData> $collection */
        $collection = FlightData::collect($flights->items(), DataCollection::class);

        return $collection;
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[ResponseExample(
        status: 200,
        name: FlightDataExample::NAME,
        value: FlightDataExample::PAYLOAD,
        summary: FlightDataExample::SUMMARY,
        description: FlightDataExample::DESCRIPTION,
    )]
    public function show(string $flight): FlightData
    {
        return FlightData::from(Flight::query()->findOrFail($flight));
    }

    /**
     * Create a new flight.
     *
     * Validates the payload through {@see CreateFlightData} and returns the
     * persisted flight wrapped in a {@see FlightData}.
     */
    #[ResponseAttribute(status: 201, description: 'The created flight', ref: FlightData::class)]
    public function store(CreateFlightData $data): FlightData
    {
        return FlightData::from(Flight::query()->create($data->toArray()));
    }

    /**
     * Update an existing flight.
     *
     * Accepts a partial payload validated by {@see UpdateFlightData}; only the
     * supplied properties are persisted thanks to `Optional` unions.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    public function update(UpdateFlightData $data, string $flight): FlightData
    {
        $model = Flight::query()->findOrFail($flight);
        $model->update($data->toArray());

        return FlightData::from($model);
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
