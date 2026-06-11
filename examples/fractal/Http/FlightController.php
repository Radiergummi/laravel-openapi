<?php

declare(strict_types=1);

namespace Examples\Fractal\Http;

use Examples\Fractal\Http\Transformers\BookingTransformer;
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

    /**
     * List a flight's bookings.
     *
     * `BookingTransformer` declares no `#[TransformerField]` — its schema is
     * inferred from the `transform()` return literal.
     *
     * @throws ModelNotFoundException When the flight does not exist.
     */
    #[FractalResponse(transformer: BookingTransformer::class, collection: true)]
    public function bookings(Manager $fractal, string $flight): JsonResponse
    {
        $model = Flight::query()->findOrFail($flight);

        $resource = new Collection($model->bookings, new BookingTransformer());

        return new JsonResponse($fractal->createData($resource)->toArray());
    }
}
