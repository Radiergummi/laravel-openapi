<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Values;

use DateTimeImmutable;

/**
 * A non-Model value object with date-typed public properties, one of them nullable, plus a
 * non-date member that answers `format()` itself.
 */
final readonly class BookingPeriodValue
{
    public function __construct(
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public MoneyValue $price,
    ) {}
}
