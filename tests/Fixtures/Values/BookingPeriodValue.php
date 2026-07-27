<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Values;

use DateTimeImmutable;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\CurrencyCode;

/**
 * A non-Model value object with date-typed public properties, one of them nullable, plus two
 * non-date members that answer `format()` themselves: one the reader cannot type at all, one it
 * types as anything but a date.
 */
final readonly class BookingPeriodValue
{
    public function __construct(
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public MoneyValue $price,
        public CurrencyCode $currency,
    ) {}
}
