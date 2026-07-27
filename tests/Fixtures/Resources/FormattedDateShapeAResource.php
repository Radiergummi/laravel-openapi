<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Values\BookingPeriodValue;

/**
 * Shape (A): `->format(…)` on a member of the value object the resource declares as a typed
 * property, alongside the nullability and non-date shapes the reader must still discriminate.
 */
class FormattedDateShapeAResource extends JsonResource
{
    public function __construct(private readonly BookingPeriodValue $period)
    {
        parent::__construct($period);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'starts_at' => $this->period->startsAt->format(DATE_ATOM),
            'starts_day' => $this->period->startsAt->format('Y-m-d'),
            // @phpstan-ignore method.nonObject (calling through a nullable member is the case under test)
            'ends_at' => $this->period->endsAt->format(DATE_ATOM),
            'ends_at_nullsafe' => $this->period->endsAt?->format(DATE_ATOM),
            'price' => $this->period->price->format('%.2f'),
            'currency' => $this->period->currency->format('%s'),
            'dynamic_format' => $this->period->startsAt->format($request->dateFormat),
        ];
    }
}
