<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\ApiResources\Http;

use Examples\ApiResources\Http\Resources\FlightResource;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Attributes\Tag;

#[Tag('Flights')]
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
    public function show(string $flight): FlightResource
    {
        return new FlightResource(Flight::query()->findOrFail($flight));
    }
}
