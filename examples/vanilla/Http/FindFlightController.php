<?php

declare(strict_types=1);

namespace Examples\Vanilla\Http;

use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Radiergummi\OpenApi\Attributes\Tag;

/**
 * Demonstrates model-class inference from a directly-returned `Model::findOrFail()` call: the
 * action is untyped, yet the success schema is recovered from the Flight model's metadata, and
 * the `findOrFail()` lookup also contributes the 404 — no #[Response] override needed.
 */
#[Tag('Flights')]
final class FindFlightController
{
    /**
     * Look up a single flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    public function show(string $flight)
    {
        return Flight::findOrFail($flight);
    }
}
