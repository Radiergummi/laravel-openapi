<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Fractal\Http;

use Examples\Fractal\Http\Transformers\FlightTransformer;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use Radiergummi\OpenApi\Attributes\Tag;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;

#[Tag('Flights')]
final class FlightController
{
    /**
     * List flights.
     *
     * Returns a paginated collection wrapped in Fractal's `{data, meta.pagination}`
     * envelope.
     */
    #[FractalResponse(transformer: FlightTransformer::class, paginated: true)]
    public function index(Manager $fractal): JsonResponse
    {
        $paginator = Flight::query()->orderBy('departs_at')->paginate();

        $resource = new Collection($paginator->items(), new FlightTransformer());

        return new JsonResponse($fractal->createData($resource)->toArray());
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[FractalResponse(transformer: FlightTransformer::class)]
    public function show(Manager $fractal, string $flight): JsonResponse
    {
        $model = Flight::query()->findOrFail($flight);

        $resource = new Item($model, new FlightTransformer());

        return new JsonResponse($fractal->createData($resource)->toArray());
    }
}
