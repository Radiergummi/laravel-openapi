<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Enums;

use function sprintf;

/**
 * A backed enum with a `format()` method of its own: the reader types the property, so a
 * `->format(…)` on it reaches the date-evidence check and must be refused there.
 */
enum CurrencyCode: string
{
    case Eur = 'EUR';
    case Usd = 'USD';

    public function format(string $pattern): string
    {
        return sprintf($pattern, $this->value);
    }
}
