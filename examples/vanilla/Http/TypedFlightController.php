<?php

declare(strict_types=1);

namespace Examples\Vanilla\Http;

use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Radiergummi\OpenApi\Attributes\Tag;

/**
 * Demonstrates model-typed returns: the response schema is derived from the Flight
 * model's metadata ($casts + @property), with no #[Response] override needed.
 */
#[Tag('Flights')]
final class TypedFlightController
{
    /**
     * List flights.
     *
     * Returns all flights. The response schema is inferred from the Flight model.
     *
     * @return Collection<int, Flight>
     */
    public function index(): Collection
    {
        return Flight::query()->orderBy('departs_at')->get();
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    public function show(string $flight): Flight
    {
        return Flight::query()->findOrFail($flight);
    }
}
