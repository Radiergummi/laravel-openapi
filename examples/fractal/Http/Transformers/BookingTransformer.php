<?php

declare(strict_types=1);

namespace Examples\Fractal\Http\Transformers;

use Examples\Shared\Models\Booking;
use League\Fractal\TransformerAbstract;

/**
 * Output contract for Booking responses — attribute-free.
 *
 * The generator reads the single `return [...]` literal of `transform()`
 * directly: model fetches type their property from the `Booking` parameter's
 * metadata, the casts type `seat_row` (integer) and `extras` (array of
 * unconstrained items), and the unreadable `reference` value stays an
 * unconstrained property (note in the generation log).
 */
final class BookingTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'passenger_name' => $booking->passenger_name,
            'seat' => $booking->seat,
            'seat_row' => (int) $booking->seat,
            'extras' => (array) $booking->seat,
            'reference' => $this->reference($booking),
        ];
    }

    private function reference(Booking $booking): string
    {
        return 'BK-' . $booking->id;
    }
}
